<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class UpdateGameSlotRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GAMESLOT_UPDATE;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|min:1|max:191',
            'notes' => 'sometimes|nullable|string|max:1000',
        ];
    }
}
