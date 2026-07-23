<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Network;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class UpdateManagedSubdomainRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_SUBDOMAIN_UPDATE;
    }

    public function rules(): array
    {
        return ['label' => 'required|string|min:1|max:63'];
    }
}
