<?php

namespace Pterodactyl\Http\Requests\Admin\Dns;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class DnsServiceProfileFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $profile = $this->route()->parameter('dnsServiceProfile');

        return [
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('dns_service_profiles', 'slug')->ignore($profile instanceof DnsServiceProfile ? $profile->id : null)],
            'name' => 'required|string|max:191',
            'protocol' => 'required|in:tcp,udp,http,https',
            'default_port' => 'nullable|required_if:portless_on_default_port,1|integer|min:1|max:65535',
            'supports_direct_dns' => 'sometimes|boolean',
            'supports_srv' => 'sometimes|boolean',
            'srv_service' => 'nullable|required_if:supports_srv,1|string|max:63|regex:/^_[a-z0-9-]+$/',
            'srv_protocol' => 'nullable|required_if:supports_srv,1|in:_tcp,_udp',
            'srv_priority' => 'required|integer|min:0|max:65535',
            'srv_weight' => 'required|integer|min:0|max:65535',
            'portless_on_default_port' => 'sometimes|boolean',
            'description' => 'nullable|string|max:2000',
        ];
    }
}
