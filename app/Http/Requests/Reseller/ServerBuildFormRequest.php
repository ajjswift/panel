<?php

namespace Pterodactyl\Http\Requests\Reseller;

use Pterodactyl\Models\Server;
use Illuminate\Support\Collection;

class ServerBuildFormRequest extends ResellerFormRequest
{
    /**
     * Resource fields only. Allocation changes, CPU pinning and the OOM killer
     * stay with the panel administrator — a reseller adjusts what it is paying
     * for, not how the container is pinned to hardware.
     */
    public function rules(): array
    {
        return Collection::make(Server::getRules())->only([
            'memory',
            'swap',
            'io',
            'cpu',
            'disk',
            'database_limit',
            'allocation_limit',
            'backup_limit',
        ])->toArray();
    }
}
