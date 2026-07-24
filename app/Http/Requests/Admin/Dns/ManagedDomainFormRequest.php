<?php

namespace Pterodactyl\Http\Requests\Admin\Dns;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class ManagedDomainFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $domain = $this->route()->parameter('managedDomain');

        return [
            'name' => 'required|string|max:191',
            'domain' => ['required', 'string', 'max:191', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/i', Rule::unique('managed_domains', 'domain')->ignore($domain instanceof ManagedDomain ? $domain->id : null)],
            'zone_id' => 'required|string|size:32|regex:/^[a-f0-9]+$/i',
            'api_token' => [$domain ? 'nullable' : 'required', 'nullable', 'string', 'min:10'],
            'enabled' => 'sometimes|boolean',
            'label_pattern' => 'nullable|string|max:255',
            'per_server_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'domain_limit' => 'nullable|integer|min:1',
            'supports_srv' => 'sometimes|boolean',
            'supports_direct_dns' => 'sometimes|boolean',
            'ttl' => 'nullable|integer|min:60|max:86400',
            'target_ipv4' => 'nullable|ipv4',
            'target_ipv6' => 'nullable|ipv6',
            'cname_target' => 'nullable|string|max:191|regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}\\.?$/i',
            'allowed_node_ids' => 'nullable|array',
            'allowed_node_ids.*' => 'integer|exists:nodes,id',
            'allowed_egg_ids' => 'nullable|array',
            'allowed_egg_ids.*' => 'integer|exists:eggs,id',
            'allowed_service_profile_ids' => 'nullable|array',
            'allowed_service_profile_ids.*' => 'integer|exists:dns_service_profiles,id',
            'reserved_labels' => 'nullable|string|max:5000',
            'description' => 'nullable|string|max:2000',
            'admin_notes' => 'nullable|string|max:5000',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $pattern = $this->input('label_pattern');
            if ($pattern && @preg_match('~' . $pattern . '~D', 'validation-test') === false) {
                $validator->errors()->add('label_pattern', 'The label pattern is not a valid regular expression.');
            }
        });
    }
}
