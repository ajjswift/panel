<?php

namespace Pterodactyl\Services\GameSlots;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Illuminate\Database\QueryException;
use Pterodactyl\Models\GameSwitchOperation;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Jobs\GameSlots\ProcessGameSwitchJob;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class GameSwitchService
{
    public function __construct(
        private ConnectionInterface $connection,
        private GameSlotAdoptionService $adoptionService,
        private DaemonServerRepository $daemonServerRepository,
    ) {
    }

    /**
     * Validate and enqueue a switch to the given destination slot. Returns the
     * durable operation record; the actual work happens on the queue.
     *
     * @throws DisplayException
     * @throws \Throwable
     */
    public function handle(Server $server, GameSlot $destination, ?User $user = null, bool $restorePower = true): GameSwitchOperation
    {
        if ($destination->server_id !== $server->id) {
            throw new DisplayException('The requested game slot does not belong to this server.');
        }

        // Contact the node before touching any state so an unreachable node
        // fails the request cleanly instead of stranding a queued operation.
        try {
            $details = $this->daemonServerRepository->setServer($server)->getDetails();
        } catch (DaemonConnectionException) {
            throw new DisplayException('The node this server is hosted on could not be reached; the game switch cannot start right now.');
        }

        $previousPowerState = $details['state'] ?? 'offline';

        // Fresh install required? Make sure there is at least some head room on
        // the disk before starting an expensive, disruptive operation.
        if ($destination->installation_status !== GameSlot::INSTALL_INSTALLED && $server->disk > 0) {
            $usedBytes = (int) ($details['utilization']['disk_bytes'] ?? 0);
            if ($usedBytes >= $server->disk * 1024 * 1024 * 0.95) {
                throw new DisplayException('There is not enough free disk space to install this game. Free up space or delete an unused slot first.');
            }
        }

        $operation = $this->connection->transaction(function () use ($server, $destination, $user, $restorePower, $previousPowerState) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            $destination = GameSlot::query()->lockForUpdate()->findOrFail($destination->id);

            $source = $this->adoptionService->handle($server);

            if (!is_null($server->status) || !is_null($server->transfer)) {
                throw new DisplayException('This server is not currently in a state that allows switching games.');
            }

            if ($server->backups()->whereNull('completed_at')->exists()) {
                throw new DisplayException('A backup is currently running for this server. Wait for it to complete before switching games.');
            }

            if ($destination->is_active) {
                throw new DisplayException('This game slot is already active.');
            }

            if (!$destination->isSwitchable()) {
                throw new DisplayException('This game slot is not currently in a state that allows it to be activated.');
            }

            if (!$destination->egg->game_switch_enabled) {
                throw new DisplayException('The game assigned to this slot has been disabled for switching by an administrator.');
            }

            try {
                $operation = new GameSwitchOperation();
                $operation->forceFill([
                    'uuid' => Uuid::uuid4()->toString(),
                    'server_id' => $server->id,
                    'source_slot_id' => $source->id,
                    'destination_slot_id' => $destination->id,
                    'requested_by' => $user?->id,
                    'state' => GameSwitchOperation::STATE_PENDING,
                    'current_stage' => GameSwitchOperation::STAGE_PENDING,
                    'checkpoint' => GameSwitchOperation::CHECKPOINT_CREATED,
                    // Unique index: a second live operation for this server is
                    // rejected by the database itself.
                    'lock_marker' => $server->id,
                    'previous_power_state' => $previousPowerState,
                    'restore_power' => $restorePower,
                ]);
                $operation->save();
            } catch (QueryException $exception) {
                if ((string) ($exception->errorInfo[0] ?? '') === '23000') {
                    throw new DisplayException('A game switch is already in progress for this server.');
                }

                throw $exception;
            }

            $server->forceFill(['status' => Server::STATUS_SWITCHING_GAME])->save();

            return $operation;
        });

        ProcessGameSwitchJob::dispatch($operation->id);

        return $operation;
    }
}
