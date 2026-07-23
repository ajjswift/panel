<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class StoreGameSlotRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GAMESLOT_CREATE;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:1|max:191',
            'egg_id' => 'required|integer|exists:eggs,id',
            'docker_image' => 'nullable|string|max:191',
            // Per-variable validation against the egg happens in the service.
            'environment' => 'nullable|array',
        ];
    }
}
