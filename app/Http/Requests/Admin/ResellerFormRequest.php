<?php

namespace Pterodactyl\Http\Requests\Admin;

use Pterodactyl\Models\Reseller;
use Illuminate\Support\Collection;

class ResellerFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $rules = Collection::make(Reseller::getRules())
            ->only(array_merge(['name', 'enabled'], Reseller::QUOTA_DIMENSIONS))
            ->toArray();

        // The owning account is only chosen when the reseller is created; it is
        // not editable afterwards, so the route decides whether this applies.
        if (is_null($this->route()->parameter('reseller'))) {
            $rules['user_id'] = ['required', 'integer', 'exists:users,id', 'unique:resellers,user_id'];
        }

        $rules['node_ids'] = 'sometimes|nullable|array';
        $rules['node_ids.*'] = 'integer|exists:nodes,id';

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'user_id' => 'owning account',
            'server_limit' => 'server limit',
            'user_limit' => 'user limit',
            'node_ids' => 'available nodes',
        ];
    }
}
