<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Network;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PreviewManagedSubdomainRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_SUBDOMAIN_CREATE;
    }

    public function rules(): array
    {
        return [
            'label' => 'required|string|min:1|max:63',
            'domain_uuid' => 'required|uuid|exists:managed_domains,uuid',
            'allocation_id' => 'required|integer|exists:allocations,id',
        ];
    }
}
