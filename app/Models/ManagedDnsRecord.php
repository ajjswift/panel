<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $managed_subdomain_id
 * @property string $provider
 * @property string $zone_id
 * @property string|null $provider_record_id
 * @property string $type
 * @property string $name
 * @property string|null $content
 * @property int $ttl
 * @property bool $proxied
 * @property int|null $srv_priority
 * @property int|null $srv_weight
 * @property int|null $srv_port
 * @property string|null $srv_target
 * @property string $sync_status
 * @property string|null $last_error_code
 */
class ManagedDnsRecord extends Model
{
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'ttl' => 'integer',
        'proxied' => 'boolean',
        'srv_priority' => 'integer',
        'srv_weight' => 'integer',
        'srv_port' => 'integer',
        'last_synchronized_at' => 'datetime',
    ];

    /** @return BelongsTo<ManagedSubdomain, $this> */
    public function managedSubdomain(): BelongsTo
    {
        return $this->belongsTo(ManagedSubdomain::class);
    }
}
