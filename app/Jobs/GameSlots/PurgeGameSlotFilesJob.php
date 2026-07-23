<?php

namespace Pterodactyl\Jobs\GameSlots;

use Illuminate\Bus\Queueable;
use Pterodactyl\Models\GameSlot;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\Services\GameSlots\GameSlotStorageService;

/**
 * Deletes an inactive slot's file store on the node, then removes the slot's
 * database row. If the purge cannot complete the slot remains visible in the
 * "deleting" state so administrators can see and retry it instead of files
 * being silently orphaned.
 */
class PurgeGameSlotFilesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 300];

    public int $timeout = 60 * 10;

    public function __construct(public int $slotId)
    {
    }

    public function handle(GameSlotStorageService $storage): void
    {
        $slot = GameSlot::query()->find($this->slotId);
        if (!$slot || $slot->state !== GameSlot::STATE_DELETING) {
            return;
        }

        // Refuse to purge if the slot somehow became active again.
        if ($slot->is_active) {
            $slot->forceFill(['state' => GameSlot::STATE_NORMAL])->save();

            return;
        }

        $storage->purgeSlotStore($slot->server, $slot);

        $slot->delete();
    }

    public function failed(?\Throwable $exception = null): void
    {
        Log::error('Failed to purge game slot files; the slot remains in the deleting state.', [
            'slot_id' => $this->slotId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
