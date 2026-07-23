<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Network;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetNetworkOverviewRequest extends ClientApiRequest
{
    public function authorize(): bool
    {
        $server = $this->route()->parameter('server');

        return $server instanceof Server && (
            $this->user()->can(Permission::ACTION_ALLOCATION_READ, $server)
            || $this->user()->can(Permission::ACTION_SUBDOMAIN_READ, $server)
        );
    }
}
