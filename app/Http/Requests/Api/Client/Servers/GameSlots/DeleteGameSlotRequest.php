<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class DeleteGameSlotRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GAMESLOT_DELETE;
    }

    public function rules(): array
    {
        return [
            // The user must retype the slot name to confirm a destructive delete.
            'confirm' => 'required|string',
        ];
    }
}
