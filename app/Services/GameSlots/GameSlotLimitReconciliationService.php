<?php

namespace Pterodactyl\Services\GameSlots;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;

/**
 * Keeps game-slot state consistent with a server's slot allowance without ever
 * destroying customer data. When the allowance drops below the number of
 * existing slots, the newest inactive, otherwise-normal slots beyond the limit
 * are flagged "over_limit" (blocking activation until resolved); the active
 * slot is always kept usable. When the allowance is raised again, previously
 * over-limit slots that now fit are restored to normal.
 */
class GameSlotLimitReconciliationService
{
    public function handle(Server $server): void
    {
        $slots = $server->gameSlots()->orderBy('id')->get();
        if ($slots->isEmpty()) {
            return;
        }

        $limit = max(1, $server->game_slot_limit);

        // The active slot always counts first and is never marked over-limit.
        $ordered = $slots->sortByDesc(fn (GameSlot $slot) => $slot->is_active ? 1 : 0)
            ->values();

        $index = 0;
        foreach ($ordered as $slot) {
            $withinLimit = $index < $limit;
            ++$index;

            if ($slot->is_active) {
                // Never disable the active slot on account of the limit.
                if ($slot->state === GameSlot::STATE_OVER_LIMIT) {
                    $slot->forceFill(['state' => GameSlot::STATE_NORMAL])->save();
                }

                continue;
            }

            if (!$withinLimit && $slot->state === GameSlot::STATE_NORMAL) {
                $slot->forceFill(['state' => GameSlot::STATE_OVER_LIMIT])->save();
            } elseif ($withinLimit && $slot->state === GameSlot::STATE_OVER_LIMIT) {
                $slot->forceFill(['state' => GameSlot::STATE_NORMAL])->save();
            }
        }
    }
}
