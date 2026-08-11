<?php

namespace Pterodactyl\Http\Requests\Reseller;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;
use Pterodactyl\Services\Resellers\ResellerContext;

class ServerFormRequest extends ResellerFormRequest
{
    /**
     * Fields a reseller may set. Deliberately an allow-list rather than "all of
     * Server::getRules() minus a few": a new admin-only column added upstream
     * should not silently become reseller-settable.
     */
    private const ALLOWED_FIELDS = [
        'owner_id',
        'name',
        'description',
        'node_id',
        'allocation_id',
        'memory',
        'swap',
        'io',
        'cpu',
        'disk',
        'nest_id',
        'egg_id',
        'startup',
        'image',
        'database_limit',
        'allocation_limit',
        'backup_limit',
    ];

    /**
     * owner_id is legitimately chosen here, so it is dropped from the base
     * class's forbidden list — withValidator() below constrains it to the
     * reseller's own users.
     */
    protected function forbiddenKeys(): array
    {
        return array_diff(parent::forbiddenKeys(), ['owner_id']);
    }

    public function rules(): array
    {
        $rules = Collection::make(Server::getRules())->only(self::ALLOWED_FIELDS)->toArray();
        $rules['description'][] = 'nullable';
        $rules['custom_image'] = 'sometimes|nullable|string';
        $rules['allocation_additional'] = 'sometimes|nullable|array';
        $rules['start_on_completion'] = 'sometimes|boolean';
        $rules['environment'] = 'sometimes|array';

        return $rules;
    }

    /**
     * Constrain every reference to a tenant-owned or granted resource. These
     * run as `exists` sub-queries scoped to the reseller, so a foreign id fails
     * validation rather than reaching the creation service.
     */
    public function withValidator(Validator $validator): void
    {
        /** @var ResellerContext $context */
        $context = app(ResellerContext::class);
        $resellerId = $context->id();

        $validator->addRules([
            'owner_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id')->where('reseller_id', $resellerId),
            ],
            'node_id' => [
                'required',
                'integer',
                Rule::exists('reseller_nodes', 'node_id')->where('reseller_id', $resellerId),
            ],
            'allocation_id' => [
                'required',
                'integer',
                'bail',
                Rule::exists('allocations', 'id')->where(function ($query) {
                    $query->where('node_id', $this->input('node_id'))->whereNull('server_id');
                }),
            ],
            'allocation_additional.*' => [
                'sometimes',
                'integer',
                Rule::exists('allocations', 'id')->where(function ($query) {
                    $query->where('node_id', $this->input('node_id'))->whereNull('server_id');
                }),
            ],
        ]);
    }
}
