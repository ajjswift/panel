<?php

namespace Pterodactyl\Http\Requests\Reseller;

use Pterodactyl\Models\User;
use Illuminate\Support\Collection;

class UserFormRequest extends ResellerFormRequest
{
    public function rules(): array
    {
        return Collection::make(
            User::getRulesForUpdate($this->route()->parameter('user'))
        )->only([
            'email',
            'username',
            'name_first',
            'name_last',
            'password',
            'language',
        ])->toArray();
    }
}
