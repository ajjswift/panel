<?php

namespace Pterodactyl\Models;

use Pterodactyl\Enum\DnsRoutingMode;
use Pterodactyl\Enum\ManagedSubdomainStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int|null $allocation_id
 * @property int $managed_domain_id
 * @property int|null $created_by
 * @property int|null $dns_service_profile_id
 * @property string $label
 * @property string $fqdn
 * @property DnsRoutingMode $routing_mode
 * @property string $detected_service
 * @property string $service_detection_source
 * @property ManagedSubdomainStatus $status
 * @property int $desired_state_version
 * @property string $public_target_type
 * @property string $public_target
 * @property int $target_port
 * @property string $connection_address
 * @property array<string, mixed> $desired_record_plan
 * @property \Carbon\Carbon|null $last_synchronized_at
 * @property string|null $last_error_code
 * @property string|null $sanitized_error_message
 * @property \Carbon\Carbon|null $provider_drift_detected_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property Server $server
 * @property Allocation|null $allocation
 * @property ManagedDomain $domain
 * @property DnsServiceProfile|null $serviceProfile
 */
class ManagedSubdomain extends Model
{
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'routing_mode' => DnsRoutingMode::class,
        'status' => ManagedSubdomainStatus::class,
        'desired_state_version' => 'integer',
        'target_port' => 'integer',
        'desired_record_plan' => 'array',
        'last_synchronized_at' => 'datetime',
        'provider_drift_detected_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid|unique:managed_subdomains,uuid',
        'server_id' => 'required|integer|exists:servers,id',
        'allocation_id' => 'nullable|integer|exists:allocations,id',
        'managed_domain_id' => 'required|integer|exists:managed_domains,id',
        'created_by' => 'nullable|integer|exists:users,id',
        'dns_service_profile_id' => 'nullable|integer|exists:dns_service_profiles,id',
        'label' => 'required|string|max:63',
        'fqdn' => 'required|string|max:191|unique:managed_subdomains,fqdn',
        'routing_mode' => 'required',
        'detected_service' => 'required|string|max:191',
        'service_detection_source' => 'required|string|max:64',
        'status' => 'required',
        'desired_state_version' => 'integer|min:1',
        'public_target_type' => 'required|in:A,AAAA,CNAME',
        'public_target' => 'required|string|max:191',
        'target_port' => 'required|integer|min:1|max:65535',
        'connection_address' => 'required|string|max:255',
        'desired_record_plan' => 'required|array',
        'last_error_code' => 'nullable|string|max:100',
        'sanitized_error_message' => 'nullable|string|max:2000',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Allocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    /** @return BelongsTo<ManagedDomain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(ManagedDomain::class, 'managed_domain_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<DnsServiceProfile, $this> */
    public function serviceProfile(): BelongsTo
    {
        return $this->belongsTo(DnsServiceProfile::class, 'dns_service_profile_id');
    }

    /** @return HasMany<ManagedDnsRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(ManagedDnsRecord::class);
    }

    /** @return HasMany<ManagedDnsSyncAttempt, $this> */
    public function syncAttempts(): HasMany
    {
        return $this->hasMany(ManagedDnsSyncAttempt::class);
    }
}
