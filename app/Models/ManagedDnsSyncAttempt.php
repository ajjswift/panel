<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $managed_subdomain_id
 * @property int $desired_state_version
 * @property string $action
 * @property string $status
 * @property int $attempt
 * @property array<int, string>|null $completed_steps
 * @property string|null $error_code
 * @property string|null $sanitized_error_message
 * @property string|null $rollback_status
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 */
class ManagedDnsSyncAttempt extends Model
{
    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'desired_state_version' => 'integer',
        'attempt' => 'integer',
        'completed_steps' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<ManagedSubdomain, $this> */
    public function managedSubdomain(): BelongsTo
    {
        return $this->belongsTo(ManagedSubdomain::class);
    }
}
