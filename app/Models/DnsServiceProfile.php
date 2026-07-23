<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string $protocol
 * @property int|null $default_port
 * @property bool $supports_direct_dns
 * @property bool $supports_srv
 * @property string|null $srv_service
 * @property string|null $srv_protocol
 * @property int $srv_priority
 * @property int $srv_weight
 * @property bool $portless_on_default_port
 * @property bool $cloudflare_proxy_eligible
 * @property bool $enabled
 * @property bool $is_system
 * @property string|null $description
 */
class DnsServiceProfile extends Model
{
    use HasFactory;

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'default_port' => 'integer',
        'supports_direct_dns' => 'boolean',
        'supports_srv' => 'boolean',
        'srv_priority' => 'integer',
        'srv_weight' => 'integer',
        'portless_on_default_port' => 'boolean',
        'cloudflare_proxy_eligible' => 'boolean',
        'enabled' => 'boolean',
        'is_system' => 'boolean',
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid|unique:dns_service_profiles,uuid',
        'slug' => 'required|string|max:100|regex:/^[a-z0-9-]+$/|unique:dns_service_profiles,slug',
        'name' => 'required|string|max:191',
        'protocol' => 'required|in:tcp,udp,http,https',
        'default_port' => 'nullable|integer|min:1|max:65535',
        'supports_direct_dns' => 'boolean',
        'supports_srv' => 'boolean',
        'srv_service' => 'nullable|string|max:63|regex:/^_[a-z0-9-]+$/',
        'srv_protocol' => 'nullable|in:_tcp,_udp',
        'srv_priority' => 'integer|min:0|max:65535',
        'srv_weight' => 'integer|min:0|max:65535',
        'portless_on_default_port' => 'boolean',
        'cloudflare_proxy_eligible' => 'boolean',
        'description' => 'nullable|string|max:2000',
        'enabled' => 'boolean',
        'is_system' => 'boolean',
    ];

    /** @return HasMany<Egg, $this> */
    public function eggs(): HasMany
    {
        return $this->hasMany(Egg::class);
    }

    /** @return HasMany<ManagedSubdomain, $this> */
    public function managedSubdomains(): HasMany
    {
        return $this->hasMany(ManagedSubdomain::class);
    }
}
