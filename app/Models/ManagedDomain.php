<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $domain
 * @property string $provider
 * @property string $zone_id
 * @property string $api_token
 * @property bool $enabled
 * @property string $label_pattern
 * @property int|null $per_server_limit
 * @property int|null $per_user_limit
 * @property int|null $domain_limit
 * @property bool $supports_srv
 * @property bool $supports_direct_dns
 * @property int $ttl
 * @property string|null $target_ipv4
 * @property string|null $target_ipv6
 * @property string|null $cname_target
 * @property array<int, int>|null $allowed_node_ids
 * @property array<int, int>|null $allowed_egg_ids
 * @property array<int, int>|null $allowed_service_profile_ids
 * @property array<int, string>|null $reserved_labels
 * @property string|null $description
 * @property string|null $admin_notes
 * @property string|null $last_provider_status
 * @property string|null $last_provider_error_code
 */
class ManagedDomain extends Model
{
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $hidden = ['api_token', 'admin_notes'];

    protected $casts = [
        'api_token' => 'encrypted',
        'enabled' => 'boolean',
        'per_server_limit' => 'integer',
        'per_user_limit' => 'integer',
        'domain_limit' => 'integer',
        'supports_srv' => 'boolean',
        'supports_direct_dns' => 'boolean',
        'ttl' => 'integer',
        'allowed_node_ids' => 'array',
        'allowed_egg_ids' => 'array',
        'allowed_service_profile_ids' => 'array',
        'reserved_labels' => 'array',
        'last_provider_check_at' => 'datetime',
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid|unique:managed_domains,uuid',
        'name' => 'required|string|max:191',
        'domain' => 'required|string|max:191|unique:managed_domains,domain',
        'provider' => 'required|in:cloudflare',
        'zone_id' => 'required|string|max:64',
        'api_token' => 'required|string',
        'enabled' => 'boolean',
        'label_pattern' => 'required|string|max:255',
        'per_server_limit' => 'nullable|integer|min:1',
        'per_user_limit' => 'nullable|integer|min:1',
        'domain_limit' => 'nullable|integer|min:1',
        'supports_srv' => 'boolean',
        'supports_direct_dns' => 'boolean',
        'ttl' => 'integer|min:60|max:86400',
        'target_ipv4' => 'nullable|ipv4',
        'target_ipv6' => 'nullable|ipv6',
        'cname_target' => 'nullable|string|max:191',
        'allowed_node_ids' => 'nullable|array',
        'allowed_egg_ids' => 'nullable|array',
        'allowed_service_profile_ids' => 'nullable|array',
        'reserved_labels' => 'nullable|array',
        'description' => 'nullable|string|max:2000',
        'admin_notes' => 'nullable|string|max:5000',
        'last_provider_status' => 'nullable|string|max:64',
        'last_provider_error_code' => 'nullable|string|max:100',
    ];

    /** @return HasMany<ManagedSubdomain, $this> */
    public function managedSubdomains(): HasMany
    {
        return $this->hasMany(ManagedSubdomain::class);
    }
}
