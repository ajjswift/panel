<?php

namespace Pterodactyl\Http\Requests\Admin\Dns;

use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class DiscoverManagedDomainRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:191', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'api_token' => ['required', 'string', 'min:10'],
        ];
    }
}
