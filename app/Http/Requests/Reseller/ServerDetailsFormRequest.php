<?php

namespace Pterodactyl\Http\Requests\Reseller;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;
use Pterodactyl\Services\Resellers\ResellerContext;

class ServerDetailsFormRequest extends ResellerFormRequest
{
    protected function forbiddenKeys(): array
    {
        return array_diff(parent::forbiddenKeys(), ['owner_id']);
    }

    public function rules(): array
    {
        $rules = Collection::make(Server::getRules())
            ->only(['owner_id', 'name', 'description'])
            ->toArray();
        $rules['description'][] = 'nullable';

        return $rules;
    }

    /**
     * A server may only be reassigned to another of this reseller's users —
     * handing one to an account outside the organisation would move it out of
     * the reseller's quota accounting.
     */
    public function withValidator(Validator $validator): void
    {
        $resellerId = app(ResellerContext::class)->id();

        $validator->addRules([
            'owner_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id')->where('reseller_id', $resellerId),
            ],
        ]);
    }
}
