<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $reseller_id
 * @property string $hostname
 * @property string $verification_token
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property string|null $last_check_error
 * @property bool $is_primary
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property Reseller $reseller
 */
class ResellerDomain extends Model
{
    /**
     * The TXT record label a reseller must publish under their hostname to
     * prove ownership of it.
     */
    public const VERIFICATION_RECORD_PREFIX = '_solstice-verify';

    public const RESOURCE_NAME = 'reseller_domain';

    protected $table = 'reseller_domains';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'reseller_id' => 'integer',
        'verified_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'is_primary' => 'boolean',
    ];

    public static array $validationRules = [
        'reseller_id' => 'required|integer|exists:resellers,id',
        'hostname' => 'required|string|max:191|unique:reseller_domains,hostname',
        'verification_token' => 'required|string|max:64',
        'is_primary' => 'boolean',
    ];

    /** @return BelongsTo<Reseller, $this> */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * The fully-qualified name of the TXT record used to verify this hostname.
     */
    public function verificationRecordName(): string
    {
        return self::VERIFICATION_RECORD_PREFIX . '.' . $this->hostname;
    }
}
