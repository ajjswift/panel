<?php

namespace Pterodactyl\Services\GameSlots;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Pterodactyl\Models\GameSwitchOperation;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Jobs\GameSlots\ProcessGameSwitchJob;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/**
 * Administrator-only recovery operations for game switching. Every action here
 * is deliberately conservative: it never deletes a slot's file store, and it
 * only ever moves the system toward a single, consistent active slot.
 */
class GameSwitchRecoveryService
{
    public function __construct(
        private ConnectionInterface $connection,
        private DaemonServerRepository $daemonServerRepository,
    ) {
    }

    /**
     * Re-dispatch a failed operation. Because the job is checkpoint-driven it
     * resumes from the last durable checkpoint rather than restarting.
     *
     * @throws DisplayException
     */
    public function retry(GameSwitchOperation $operation): GameSwitchOperation
    {
        if (!in_array($operation->state, [
            GameSwitchOperation::STATE_FAILED_ROLLED_BACK,
            GameSwitchOperation::STATE_FAILED_REQUIRES_ACTION,
        ], true)) {
            throw new DisplayException('Only failed operations can be retried.');
        }

        return $this->connection->transaction(function () use ($operation) {
            $server = Server::query()->lockForUpdate()->findOrFail($operation->server_id);

            // A different operation must not already hold the lock.
            $conflict = GameSwitchOperation::query()
                ->where('server_id', $server->id)
                ->whereIn('state', GameSwitchOperation::ACTIVE_STATES)
                ->where('id', '!=', $operation->id)
                ->exists();
            if ($conflict) {
                throw new DisplayException('Another switch operation is already active for this server.');
            }

            $operation->forceFill([
                'state' => GameSwitchOperation::STATE_PENDING,
                'current_stage' => GameSwitchOperation::STAGE_PENDING,
                'lock_marker' => $server->id,
                'error_code' => null,
                'error_message' => null,
                'rollback_state' => null,
                'failed_at' => null,
            ])->save();

            $server->forceFill(['status' => Server::STATUS_SWITCHING_GAME])->save();

            // Clear recovery flags on the involved slots so the job can proceed.
            foreach ([$operation->sourceSlot, $operation->destinationSlot] as $slot) {
                if ($slot && $slot->state === GameSlot::STATE_RECOVERY_REQUIRED) {
                    $slot->forceFill(['state' => GameSlot::STATE_NORMAL])->save();
                }
            }

            ProcessGameSwitchJob::dispatch($operation->id);

            return $operation->refresh();
        });
    }

    /**
     * Declare a specific slot to be the authoritative active slot and clear the
     * server's switching status. Used when a switch failed so badly that the
     * automatic rollback could not run; an administrator inspects the recovery
     * view, confirms which slot's files are actually live, and pins it.
     *
     * This never moves files — it only corrects database state to match what
     * the administrator has confirmed on disk.
     *
     * @throws DisplayException
     */
    public function forceActiveSlot(Server $server, GameSlot $slot): void
    {
        if ($slot->server_id !== $server->id) {
            throw new DisplayException('That slot does not belong to this server.');
        }

        $this->connection->transaction(function () use ($server, $slot) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);

            // Clear any active operation lock.
            GameSwitchOperation::query()
                ->where('server_id', $server->id)
                ->whereIn('state', GameSwitchOperation::ACTIVE_STATES)
                ->update([
                    'state' => GameSwitchOperation::STATE_FAILED_REQUIRES_ACTION,
                    'lock_marker' => null,
                ]);

            // Deactivate all slots first so the unique index cannot be violated,
            // then activate the chosen one.
            $server->gameSlots()->update(['is_active' => false, 'active_marker' => null]);

            $fresh = GameSlot::query()->lockForUpdate()->findOrFail($slot->id);
            $fresh->forceFill([
                'is_active' => true,
                'active_marker' => $server->id,
                'state' => GameSlot::STATE_NORMAL,
                'last_activated_at' => now(),
            ])->save();

            // Any remaining recovery-flagged slots return to normal.
            $server->gameSlots()
                ->where('state', GameSlot::STATE_RECOVERY_REQUIRED)
                ->update(['state' => GameSlot::STATE_NORMAL]);

            $server->forceFill(['status' => null])->save();
        });

        // Push the corrected configuration to Wings so the running definition
        // matches the pinned slot.
        try {
            $this->daemonServerRepository->setServer($server->refresh())->sync();
        } catch (\Throwable) {
            // Best effort; Wings re-pulls config on next boot regardless.
        }
    }

    /**
     * Clear a stuck operation lock and return the server to normal operation
     * without changing which slot is active. Used when the worker died leaving
     * the lock set but the filesystem is known to be consistent.
     *
     * @throws DisplayException
     */
    public function clearLock(Server $server): void
    {
        $this->connection->transaction(function () use ($server) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);

            $updated = GameSwitchOperation::query()
                ->where('server_id', $server->id)
                ->whereIn('state', GameSwitchOperation::ACTIVE_STATES)
                ->update([
                    'state' => GameSwitchOperation::STATE_FAILED_REQUIRES_ACTION,
                    'lock_marker' => null,
                    'error_code' => 'lock_cleared',
                    'error_message' => 'The operation lock was cleared by an administrator.',
                ]);

            if ($server->status === Server::STATUS_SWITCHING_GAME) {
                $server->forceFill(['status' => null])->save();
            }

            if ($updated === 0 && $server->status !== null) {
                throw new DisplayException('There was no active switch operation to clear for this server.');
            }
        });
    }

    /**
     * Self-healing pass run whenever the game slots page loads. If the server is
     * flagged as switching but no operation is actually live (the worker died,
     * the request was killed, or a transient left the flag set), the switch is
     * not really happening — quietly return the server to normal and guarantee a
     * single active slot. Safe by construction: it only ever runs when there is
     * no pending/running operation to disturb.
     */
    public function healStaleState(Server $server): bool
    {
        if ($server->status !== Server::STATUS_SWITCHING_GAME) {
            // Still guarantee exactly one active slot even when not switching, so
            // a half-finished commit never leaves the page without an active game.
            return $this->ensureSingleActiveSlot($server);
        }

        $liveOperation = GameSwitchOperation::query()
            ->where('server_id', $server->id)
            ->whereIn('state', GameSwitchOperation::ACTIVE_STATES)
            ->exists();

        if ($liveOperation) {
            return false;
        }

        $this->connection->transaction(function () use ($server) {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);

            // Re-check under the lock: a switch may have started between the read
            // above and acquiring the lock.
            $stillLive = GameSwitchOperation::query()
                ->where('server_id', $locked->id)
                ->whereIn('state', GameSwitchOperation::ACTIVE_STATES)
                ->exists();
            if (!$stillLive && $locked->status === Server::STATUS_SWITCHING_GAME) {
                $locked->forceFill(['status' => null])->save();
            }

            $this->ensureSingleActiveSlot($locked);
        });

        $server->refresh();

        return true;
    }

    /**
     * Owner-triggered "get me unstuck" action. Clears any lingering operation
     * lock, returns the server to normal, and guarantees a single active slot —
     * without ever moving files. This is the escape hatch for a broken database
     * state so a user is never permanently stranded waiting on a dead switch.
     */
    public function resetForServer(Server $server): void
    {
        $this->connection->transaction(function () use ($server) {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);

            GameSwitchOperation::query()
                ->where('server_id', $locked->id)
                ->whereIn('state', GameSwitchOperation::ACTIVE_STATES)
                ->update([
                    'state' => GameSwitchOperation::STATE_FAILED_REQUIRES_ACTION,
                    'lock_marker' => null,
                    'error_code' => 'reset_by_user',
                    'error_message' => 'The switch was reset. Your game files are all safe — nothing was deleted.',
                ]);

            if ($locked->status === Server::STATUS_SWITCHING_GAME) {
                $locked->forceFill(['status' => null])->save();
            }

            $this->ensureSingleActiveSlot($locked);
        });

        try {
            $this->daemonServerRepository->setServer($server->refresh())->sync();
        } catch (\Throwable) {
            // Best effort; Wings re-pulls config on next boot regardless.
        }
    }

    /**
     * Guarantee the server has exactly one active slot. When a commit was
     * interrupted the volume can hold a slot's files with nothing marked active;
     * pick the most sensible slot (the one last activated, else the earliest)
     * and pin it in the database. Returns whether any change was made. Never
     * touches files.
     */
    private function ensureSingleActiveSlot(Server $server): bool
    {
        $slots = $server->gameSlots()->get();
        if ($slots->isEmpty()) {
            return false;
        }

        $active = $slots->where('is_active', true);
        if ($active->count() === 1) {
            return false;
        }

        // Prefer the slot most recently activated; fall back to the oldest slot
        // so a brand-new server still ends up with a defined active game.
        $chosen = $slots->sortByDesc(fn (GameSlot $slot) => $slot->last_activated_at?->getTimestamp() ?? 0)
            ->sortByDesc('is_active')
            ->first();

        $this->connection->transaction(function () use ($server, $chosen) {
            $server->gameSlots()->update(['is_active' => false, 'active_marker' => null]);

            $fresh = GameSlot::query()->lockForUpdate()->findOrFail($chosen->id);
            $fresh->forceFill([
                'is_active' => true,
                'active_marker' => $server->id,
                'state' => $fresh->state === GameSlot::STATE_RECOVERY_REQUIRED ? GameSlot::STATE_NORMAL : $fresh->state,
                'last_activated_at' => $fresh->last_activated_at ?? now(),
            ])->save();
        });

        return true;
    }

    /**
     * Assemble a diagnostic snapshot comparing database state to node state so
     * an administrator can decide how to recover.
     */
    public function diagnostics(Server $server): array
    {
        $nodeState = null;
        $nodeError = null;
        try {
            $details = $this->daemonServerRepository->setServer($server)->getDetails();
            $nodeState = $details['state'] ?? null;
        } catch (\Throwable $exception) {
            $nodeError = $exception->getMessage();
        }

        $active = $server->activeGameSlot()->first();
        $operation = $server->gameSwitchOperations()->orderByDesc('id')->first();

        return [
            'server_status' => $server->status,
            'database_active_slot' => $active?->only(['uuid', 'name', 'egg_id']),
            'current_egg_id' => $server->egg_id,
            'current_image' => $server->image,
            'node_reachable' => is_null($nodeError),
            'node_power_state' => $nodeState,
            'node_error' => $nodeError,
            'latest_operation' => $operation?->only([
                'uuid', 'state', 'current_stage', 'checkpoint', 'rollback_state', 'error_code',
            ]),
            'latest_operation_context' => $operation?->internal_error_context,
        ];
    }
}
