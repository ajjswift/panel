<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\GameSlot;

class GameSlotTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return GameSlot::RESOURCE_NAME;
    }

    /**
     * Note: the slot's internal storage_reference (a filesystem path fragment)
     * is deliberately never exposed through the client API.
     */
    public function transform(GameSlot $slot): array
    {
        return [
            'uuid' => $slot->uuid,
            'name' => $slot->name,
            'egg_uuid' => $slot->egg->uuid,
            'egg_name' => $slot->egg->name,
            'nest_id' => $slot->nest_id,
            'docker_image' => $slot->docker_image,
            'installation_status' => $slot->installation_status,
            'state' => $slot->state,
            'is_active' => $slot->is_active,
            'disk_usage_bytes' => $slot->disk_usage_bytes,
            'disk_usage_is_cached' => !is_null($slot->disk_scanned_at),
            'last_activated_at' => $slot->last_activated_at?->toAtomString(),
            'last_switch_completed_at' => $slot->last_switch_completed_at?->toAtomString(),
            'notes' => $slot->notes,
            'created_at' => $slot->created_at?->toAtomString(),
            'updated_at' => $slot->updated_at?->toAtomString(),
        ];
    }
}
