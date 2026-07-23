<?php

namespace Pterodactyl\Jobs\GameSlots;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Pterodactyl\Models\ServerVariable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\Models\GameSwitchOperation;
use Pterodactyl\Jobs\Dns\ReconcileServerManagedDnsJob;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\GameSlots\GameSlotStorageService;

/**
 * Executes a game switch as a resumable state machine. The operation row's
 * "checkpoint" column is the single source of truth: each stage is skipped if
 * its checkpoint has already been recorded, so the job can be re-dispatched
 * after a crash, worker restart, or administrator retry and will continue from
 * exactly where it stopped. Failures before the commit checkpoint trigger an
 * automatic rollback that restores the source slot's files and configuration.
 */
class ProcessGameSwitchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Retries are handled explicitly through the retry endpoint so that a
     * failure always resolves to a deliberate rollback rather than the queue
     * blindly re-running a half-completed filesystem operation.
     */
    public int $tries = 1;

    public int $timeout = 60 * 30;

    private const STOP_TIMEOUT_SECONDS = 120;
    private const KILL_TIMEOUT_SECONDS = 30;
    private const INSTALL_TIMEOUT_SECONDS = 60 * 15;

    private GameSwitchOperation $operation;
    private Server $server;
    private GameSlot $source;
    private GameSlot $destination;

    private DaemonPowerRepository $powerRepository;
    private DaemonServerRepository $serverRepository;
    private GameSlotStorageService $storage;

    public function __construct(public int $operationId)
    {
    }

    public function handle(
        DaemonPowerRepository $powerRepository,
        DaemonServerRepository $serverRepository,
        GameSlotStorageService $storage,
    ): void {
        $this->powerRepository = $powerRepository;
        $this->serverRepository = $serverRepository;
        $this->storage = $storage;

        $operation = GameSwitchOperation::query()->find($this->operationId);
        if (!$operation || $operation->isTerminal()) {
            return;
        }

        $this->operation = $operation;
        $this->server = $operation->server;
        $this->source = $operation->sourceSlot;
        $this->destination = $operation->destinationSlot;

        $operation->forceFill([
            'state' => GameSwitchOperation::STATE_RUNNING,
            'started_at' => $operation->started_at ?? Carbon::now(),
            'retry_count' => $operation->started_at ? $operation->retry_count + 1 : $operation->retry_count,
        ])->save();

        try {
            $this->stageStopServer();
            $this->stageSaveSource();
            $this->stagePrepareDestination();
            $this->stageUpdateConfiguration();
            $this->stageSyncWings();
            $this->stageInstall();
            $this->stageCommit();
            $this->stageRestorePower();
            $this->finalize();
        } catch (\Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    /**
     * Gracefully stop the server, escalating to a kill if it does not reach an
     * offline state within the timeout.
     */
    private function stageStopServer(): void
    {
        if ($this->passed(GameSwitchOperation::CHECKPOINT_SERVER_STOPPED)) {
            return;
        }

        $this->operation->setStage(GameSwitchOperation::STAGE_STOPPING);

        if ($this->currentPowerState() !== 'offline') {
            $this->powerRepository->setServer($this->server)->send('stop');

            if (!$this->waitForOffline(self::STOP_TIMEOUT_SECONDS)) {
                $this->powerRepository->setServer($this->server)->send('kill');

                if (!$this->waitForOffline(self::KILL_TIMEOUT_SECONDS)) {
                    throw new \RuntimeException('The server did not reach an offline state within the allowed time.');
                }
            }
        }

        $this->operation->advance(GameSwitchOperation::CHECKPOINT_SERVER_STOPPED, GameSwitchOperation::STAGE_SAVING_SOURCE);
    }

    /**
     * Persist the source slot's live configuration and move its files into the
     * slot store.
     */
    private function stageSaveSource(): void
    {
        if ($this->passed(GameSwitchOperation::CHECKPOINT_SOURCE_SAVED)) {
            return;
        }

        $this->operation->setStage(GameSwitchOperation::STAGE_SAVING_SOURCE);

        // Snapshot the server's current startup configuration into the source
        // slot so it can be restored identically later.
        $environment = [];
        foreach ($this->server->variables as $variable) {
            $environment[$variable->env_variable] = $variable->server_value ?? $variable->default_value;
        }

        $this->source->forceFill([
            'nest_id' => $this->server->nest_id,
            'egg_id' => $this->server->egg_id,
            'docker_image' => $this->server->image,
            'startup' => $this->server->startup,
            'environment' => $environment,
            'disk_usage_bytes' => $this->estimateActiveSlotUsage(),
            'disk_scanned_at' => Carbon::now(),
        ])->save();

        $this->storage->stashActiveFiles($this->server, $this->source);

        $this->operation->advance(GameSwitchOperation::CHECKPOINT_SOURCE_SAVED, GameSwitchOperation::STAGE_PREPARING_DESTINATION);
    }

    private function stagePrepareDestination(): void
    {
        if ($this->passed(GameSwitchOperation::CHECKPOINT_DESTINATION_PREPARED)) {
            return;
        }

        $this->operation->setStage(GameSwitchOperation::STAGE_PREPARING_DESTINATION);

        $this->storage->restoreSlotFiles($this->server, $this->destination);

        $this->operation->advance(GameSwitchOperation::CHECKPOINT_DESTINATION_PREPARED, GameSwitchOperation::STAGE_UPDATING_CONFIGURATION);
    }

    /**
     * Point the server at the destination slot's egg, image, startup command,
     * and variables.
     */
    private function stageUpdateConfiguration(): void
    {
        if ($this->passed(GameSwitchOperation::CHECKPOINT_CONFIG_UPDATED)) {
            return;
        }

        $this->operation->setStage(GameSwitchOperation::STAGE_UPDATING_CONFIGURATION);

        $this->applySlotConfiguration($this->destination);

        $this->operation->advance(GameSwitchOperation::CHECKPOINT_CONFIG_UPDATED, GameSwitchOperation::STAGE_SYNCING);
    }

    private function stageSyncWings(): void
    {
        if ($this->passed(GameSwitchOperation::CHECKPOINT_WINGS_SYNCED)) {
            return;
        }

        $this->operation->setStage(GameSwitchOperation::STAGE_SYNCING);

        $this->serverRepository->setServer($this->server->refresh())->sync();

        $this->operation->advance(GameSwitchOperation::CHECKPOINT_WINGS_SYNCED, GameSwitchOperation::STAGE_INSTALLING);
    }

    /**
     * Run the destination egg's installer if the slot has never been installed.
     */
    private function stageInstall(): void
    {
        if ($this->passed(GameSwitchOperation::CHECKPOINT_INSTALLED)) {
            return;
        }

        if ($this->destination->installation_status !== GameSlot::INSTALL_INSTALLED) {
            $this->operation->setStage(GameSwitchOperation::STAGE_INSTALLING);

            $this->destination->forceFill(['installation_status' => GameSlot::INSTALL_INSTALLING])->save();

            $this->server->forceFill(['status' => Server::STATUS_INSTALLING])->save();
            $this->serverRepository->setServer($this->server)->reinstall();

            $deadline = Carbon::now()->addSeconds(self::INSTALL_TIMEOUT_SECONDS);
            do {
                sleep(5);
                $status = Server::query()->whereKey($this->server->id)->value('status');
            } while ($status === Server::STATUS_INSTALLING && Carbon::now()->lt($deadline));

            // Regardless of the outcome we own the status again from here.
            $this->server->forceFill(['status' => Server::STATUS_SWITCHING_GAME])->save();

            if ($status !== null && $status !== Server::STATUS_SWITCHING_GAME) {
                $this->destination->forceFill(['installation_status' => GameSlot::INSTALL_FAILED])->save();

                throw new \RuntimeException($status === Server::STATUS_INSTALLING ? 'The game installation did not complete within the allowed time.' : 'The game installation failed on the node.');
            }

            $this->destination->forceFill(['installation_status' => GameSlot::INSTALL_INSTALLED])->save();
        }

        $this->operation->advance(GameSwitchOperation::CHECKPOINT_INSTALLED, GameSwitchOperation::STAGE_VALIDATING);
    }

    /**
     * Atomically flip the active slot in the database. This is the commit
     * point: once recorded, the switch is considered successful and any later
     * failure will not trigger a rollback.
     */
    private function stageCommit(): void
    {
        if ($this->passed(GameSwitchOperation::CHECKPOINT_COMMITTED)) {
            return;
        }

        $this->operation->setStage(GameSwitchOperation::STAGE_VALIDATING);

        // Final validation: the node must still know about the server and it
        // must be offline with its files in place before we commit.
        $details = $this->serverRepository->setServer($this->server)->getDetails();
        if (($details['state'] ?? 'offline') !== 'offline') {
            throw new \RuntimeException('The server is unexpectedly running before commit; refusing to finalize the switch.');
        }

        DB::transaction(function () {
            $source = GameSlot::query()->lockForUpdate()->findOrFail($this->source->id);
            $destination = GameSlot::query()->lockForUpdate()->findOrFail($this->destination->id);

            $source->markInactive();
            $destination->markActive();
            $destination->forceFill(['last_switch_completed_at' => Carbon::now()])->save();
        });

        $this->operation->advance(GameSwitchOperation::CHECKPOINT_COMMITTED, GameSwitchOperation::STAGE_RESTORING_POWER);
    }

    private function stageRestorePower(): void
    {
        if (!$this->operation->restore_power || !in_array($this->operation->previous_power_state, ['running', 'starting'], true)) {
            return;
        }

        $this->operation->setStage(GameSwitchOperation::STAGE_RESTORING_POWER);

        try {
            $this->powerRepository->setServer($this->server)->send('start');
        } catch (\Throwable $exception) {
            // Post-commit power failures never fail the switch itself.
            Log::warning('Failed to restore power state after game switch.', [
                'operation' => $this->operation->uuid,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function finalize(): void
    {
        $this->server->forceFill(['status' => null])->save();

        // Re-sync so Wings picks up the store denylist for normal operation.
        try {
            $this->serverRepository->setServer($this->server->refresh())->sync();
        } catch (\Throwable) {
            // The next config pull will apply it; not fatal.
        }

        $this->operation->forceFill([
            'state' => GameSwitchOperation::STATE_COMPLETED,
            'current_stage' => GameSwitchOperation::STAGE_COMPLETED,
            'completed_at' => Carbon::now(),
            'lock_marker' => null,
        ])->save();

        ReconcileServerManagedDnsJob::dispatch($this->server->id);
    }

    private function handleFailure(\Throwable $exception): void
    {
        Log::error('Game switch operation failed.', [
            'operation' => $this->operation->uuid,
            'server' => $this->server->uuid,
            'checkpoint' => $this->operation->checkpoint,
            'exception' => $exception,
        ]);

        $context = [
            'checkpoint' => $this->operation->checkpoint,
            'stage' => $this->operation->current_stage,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
        ];

        // Failures after the commit point never roll back: the destination is
        // already the active slot and its files are in place.
        if ($this->passed(GameSwitchOperation::CHECKPOINT_COMMITTED)) {
            $this->server->forceFill(['status' => null])->save();
            $this->operation->forceFill([
                'state' => GameSwitchOperation::STATE_COMPLETED,
                'current_stage' => GameSwitchOperation::STAGE_COMPLETED,
                'completed_at' => Carbon::now(),
                'lock_marker' => null,
                'internal_error_context' => $context,
            ])->save();

            ReconcileServerManagedDnsJob::dispatch($this->server->id);

            return;
        }

        $this->operation->setStage(GameSwitchOperation::STAGE_ROLLING_BACK);

        try {
            $this->rollback();

            $this->server->forceFill(['status' => null])->save();

            try {
                $this->serverRepository->setServer($this->server->refresh())->sync();
            } catch (\Throwable) {
                // Config re-sync is best effort during rollback.
            }

            if ($this->operation->restore_power && in_array($this->operation->previous_power_state, ['running', 'starting'], true)) {
                try {
                    $this->powerRepository->setServer($this->server)->send('start');
                } catch (\Throwable) {
                    // Power restore after rollback is best effort.
                }
            }

            $this->operation->forceFill([
                'state' => GameSwitchOperation::STATE_FAILED_ROLLED_BACK,
                'current_stage' => GameSwitchOperation::STAGE_FAILED,
                'failed_at' => Carbon::now(),
                'lock_marker' => null,
                'rollback_state' => GameSwitchOperation::ROLLBACK_SUCCEEDED,
                'error_code' => 'switch_failed',
                'error_message' => 'The game switch could not be completed. Your previous game was restored automatically.',
                'internal_error_context' => $context,
            ])->save();
        } catch (\Throwable $rollbackException) {
            Log::critical('Game switch rollback failed; administrator action is required.', [
                'operation' => $this->operation->uuid,
                'server' => $this->server->uuid,
                'exception' => $rollbackException,
            ]);

            // Deliberately leave the server in the switching state: both slot
            // datasets are preserved on disk and nothing else may touch the
            // filesystem until an administrator repairs the state.
            $this->source->forceFill(['state' => GameSlot::STATE_RECOVERY_REQUIRED])->save();
            $this->destination->forceFill(['state' => GameSlot::STATE_RECOVERY_REQUIRED])->save();

            $this->operation->forceFill([
                'state' => GameSwitchOperation::STATE_FAILED_REQUIRES_ACTION,
                'current_stage' => GameSwitchOperation::STAGE_FAILED,
                'failed_at' => Carbon::now(),
                'rollback_state' => GameSwitchOperation::ROLLBACK_FAILED,
                'error_code' => 'switch_failed_recovery_required',
                'error_message' => 'The game switch failed and could not be rolled back automatically. An administrator has to review this server before it can be used again.',
                'internal_error_context' => $context + [
                    'rollback_exception_class' => get_class($rollbackException),
                    'rollback_exception_message' => $rollbackException->getMessage(),
                ],
            ])->save();
        }
    }

    /**
     * Undo everything up to the last recorded checkpoint, in reverse order.
     * Every step uses the same idempotent, re-listing move helpers as the
     * forward path, so a retried rollback also resumes safely.
     */
    private function rollback(): void
    {
        // Once the source stash checkpoint was recorded, anything sitting at
        // the volume root belongs to the destination slot (a partial or full
        // restore); move it back into the destination store first. Before that
        // checkpoint the root still holds source files, which must NOT be
        // swept into the destination's store.
        if ($this->passed(GameSwitchOperation::CHECKPOINT_SOURCE_SAVED)) {
            $this->storage->stashActiveFiles($this->server, $this->destination);
        }

        // Bring the source slot's files back to the volume root. Safe even if
        // stashing never started: the moves are idempotent and re-listed.
        $this->storage->restoreSlotFiles($this->server, $this->source);

        // Restore the server's configuration to the source slot's snapshot.
        if ($this->passed(GameSwitchOperation::CHECKPOINT_CONFIG_UPDATED)) {
            $this->applySlotConfiguration($this->source);
        }
    }

    /**
     * Write a slot's egg, image, startup command, and environment onto the
     * parent server inside a transaction.
     */
    private function applySlotConfiguration(GameSlot $slot): void
    {
        DB::transaction(function () use ($slot) {
            $this->server->forceFill([
                'nest_id' => $slot->nest_id,
                'egg_id' => $slot->egg_id,
                'image' => $slot->docker_image,
                'startup' => $slot->startup,
            ])->save();

            $environment = $slot->environment?->getArrayCopy() ?? [];
            foreach ($slot->egg->variables as $variable) {
                ServerVariable::query()->updateOrCreate([
                    'server_id' => $this->server->id,
                    'variable_id' => $variable->id,
                ], [
                    'variable_value' => $environment[$variable->env_variable] ?? $variable->default_value ?? '',
                ]);
            }
        });

        $this->server->refresh();
    }

    private function passed(string $checkpoint): bool
    {
        $order = [
            GameSwitchOperation::CHECKPOINT_CREATED,
            GameSwitchOperation::CHECKPOINT_SERVER_STOPPED,
            GameSwitchOperation::CHECKPOINT_SOURCE_SAVED,
            GameSwitchOperation::CHECKPOINT_DESTINATION_PREPARED,
            GameSwitchOperation::CHECKPOINT_CONFIG_UPDATED,
            GameSwitchOperation::CHECKPOINT_WINGS_SYNCED,
            GameSwitchOperation::CHECKPOINT_INSTALLED,
            GameSwitchOperation::CHECKPOINT_COMMITTED,
        ];

        return array_search($this->operation->checkpoint, $order, true) >= array_search($checkpoint, $order, true);
    }

    private function currentPowerState(): string
    {
        $details = $this->serverRepository->setServer($this->server)->getDetails();

        return $details['state'] ?? 'offline';
    }

    private function waitForOffline(int $timeoutSeconds): bool
    {
        $deadline = Carbon::now()->addSeconds($timeoutSeconds);

        while (Carbon::now()->lt($deadline)) {
            if ($this->currentPowerState() === 'offline') {
                return true;
            }

            sleep(3);
        }

        return $this->currentPowerState() === 'offline';
    }

    /**
     * Best-effort estimate of the active slot's disk usage: the node's total
     * volume usage minus the cached sizes of all inactive stores.
     */
    private function estimateActiveSlotUsage(): int
    {
        try {
            $details = $this->serverRepository->setServer($this->server)->getDetails();
            $total = (int) ($details['utilization']['disk_bytes'] ?? 0);
        } catch (\Throwable) {
            return $this->source->disk_usage_bytes;
        }

        $inactive = (int) $this->server->gameSlots()
            ->where('is_active', false)
            ->sum('disk_usage_bytes');

        return max(0, $total - $inactive);
    }

    public function failed(?\Throwable $exception = null): void
    {
        // The queue can fail a job without handle() completing (e.g. timeout).
        $operation = GameSwitchOperation::query()->find($this->operationId);
        if (!$operation || $operation->isTerminal()) {
            return;
        }

        $operation->forceFill([
            'state' => GameSwitchOperation::STATE_FAILED_REQUIRES_ACTION,
            'current_stage' => GameSwitchOperation::STAGE_FAILED,
            'failed_at' => Carbon::now(),
            'rollback_state' => GameSwitchOperation::ROLLBACK_FAILED,
            'error_code' => 'switch_worker_failed',
            'error_message' => 'The game switch was interrupted unexpectedly. An administrator can retry the operation from the recovery tools.',
            'internal_error_context' => [
                'checkpoint' => $operation->checkpoint,
                'exception_class' => $exception ? get_class($exception) : null,
                'exception_message' => $exception?->getMessage(),
            ],
        ])->save();
    }
}
