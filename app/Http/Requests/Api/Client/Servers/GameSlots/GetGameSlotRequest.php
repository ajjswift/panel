<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\GameSlots;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetGameSlotRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GAMESLOT_READ;
    }
}
