<?php

namespace Pterodactyl\Http\Requests\Reseller;

use Pterodactyl\Models\User;
use Illuminate\Support\Collection;

class NewUserFormRequest extends ResellerFormRequest
{
    /**
     * Note the absence of `root_admin` compared to the admin equivalent: a
     * reseller may never create an administrator. The key is also stripped in
     * ResellerFormRequest::prepareForValidation(), so it cannot arrive by
     * mass-assignment even if this list changes.
     */
    public function rules(): array
    {
        return Collection::make(User::getRules())->only([
            'email',
            'username',
            'name_first',
            'name_last',
            'password',
            'language',
        ])->toArray();
    }
}
