<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class UpdateGameSlotStartupRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GAMESLOT_STARTUP;
    }

    public function rules(): array
    {
        return [
            'docker_image' => 'nullable|string|max:191',
            'environment' => 'nullable|array',
        ];
    }
}
