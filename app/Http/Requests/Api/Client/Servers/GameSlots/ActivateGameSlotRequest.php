<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class ActivateGameSlotRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GAMESLOT_SWITCH;
    }

    public function rules(): array
    {
        return [
            // When true the server is started again after the switch; otherwise
            // it is left stopped regardless of its prior power state.
            'restart_after' => 'sometimes|boolean',
        ];
    }
}
