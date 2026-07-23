<?php

namespace Pterodactyl\Services\GameSlots;

use Pterodactyl\Models\GameSlot;
use Pterodactyl\Models\GameSwitchOperation;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Jobs\GameSlots\PurgeGameSlotFilesJob;

class GameSlotDeletionService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * Delete an inactive game slot. The database row is flagged immediately and
     * the (potentially large) file purge runs as a queued job; the row itself
     * is removed only after the files are gone so a failed purge remains
     * visible to administrators rather than silently orphaning data.
     *
     * @throws DisplayException
     * @throws \Throwable
     */
    public function handle(GameSlot $slot): void
    {
        $this->connection->transaction(function () use ($slot) {
            $slot = GameSlot::query()->lockForUpdate()->findOrFail($slot->id);

            if ($slot->is_active) {
                throw new DisplayException('The active game slot cannot be deleted. Switch to another slot first.');
            }

            if ($slot->installation_status === GameSlot::INSTALL_INSTALLING) {
                throw new DisplayException('This slot is currently installing and cannot be deleted.');
            }

            $conflict = $slot->server->gameSwitchOperations()
                ->whereIn('state', GameSwitchOperation::ACTIVE_STATES)
                ->exists();
            if ($conflict) {
                throw new DisplayException('A game switch is currently in progress for this server; slots cannot be deleted until it completes.');
            }

            $slot->forceFill(['state' => GameSlot::STATE_DELETING])->save();
        });

        PurgeGameSlotFilesJob::dispatch($slot->id);
    }
}
