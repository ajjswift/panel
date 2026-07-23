<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;

/**
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int $nest_id
 * @property int $egg_id
 * @property string $name
 * @property string $docker_image
 * @property string $startup
 * @property \ArrayObject|null $environment
 * @property string $storage_reference
 * @property string $installation_status
 * @property string $state
 * @property bool $is_active
 * @property int|null $active_marker
 * @property int $disk_usage_bytes
 * @property \Illuminate\Support\Carbon|null $disk_scanned_at
 * @property \Illuminate\Support\Carbon|null $last_activated_at
 * @property \Illuminate\Support\Carbon|null $last_switch_completed_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property Server $server
 * @property Egg $egg
 * @property Nest $nest
 */
class GameSlot extends Model
{
    use HasFactory;

    public const RESOURCE_NAME = 'game_slot';

    public const INSTALL_NOT_INSTALLED = 'not_installed';
    public const INSTALL_INSTALLING = 'installing';
    public const INSTALL_INSTALLED = 'installed';
    public const INSTALL_FAILED = 'failed';

    public const STATE_NORMAL = 'normal';
    // The slot exceeds the server's current slot allowance and cannot be
    // activated until an administrator resolves the conflict.
    public const STATE_OVER_LIMIT = 'over_limit';
    // An administrator disabled the slot explicitly.
    public const STATE_DISABLED = 'disabled';
    // A switch failed in a way that requires administrative review.
    public const STATE_RECOVERY_REQUIRED = 'recovery_required';
    // Deletion has been requested; the queued file purge has not finished yet.
    public const STATE_DELETING = 'deleting';

    protected $table = 'game_slots';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'server_id' => 'integer',
        'nest_id' => 'integer',
        'egg_id' => 'integer',
        'is_active' => 'boolean',
        'active_marker' => 'integer',
        'disk_usage_bytes' => 'integer',
        'environment' => AsEncryptedArrayObject::class,
        'disk_scanned_at' => 'datetime',
        'last_activated_at' => 'datetime',
        'last_switch_completed_at' => 'datetime',
    ];

    protected $attributes = [
        'installation_status' => self::INSTALL_NOT_INSTALLED,
        'state' => self::STATE_NORMAL,
        'is_active' => false,
        'disk_usage_bytes' => 0,
    ];

    public static array $validationRules = [
        'server_id' => 'required|numeric|exists:servers,id',
        'nest_id' => 'required|numeric|exists:nests,id',
        'egg_id' => 'required|numeric|exists:eggs,id',
        'uuid' => 'required|uuid',
        'name' => 'required|string|min:1|max:191',
        'docker_image' => ['required', 'string', 'max:191', 'regex:/^~?[\w\.\/\-:@ ]*$/'],
        'startup' => 'required|string',
        'storage_reference' => 'required|string|max:191',
        'installation_status' => 'required|string|in:not_installed,installing,installed,failed',
        'state' => 'required|string|in:normal,over_limit,disabled,recovery_required,deleting',
        'is_active' => 'boolean',
        'notes' => 'nullable|string|max:1000',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Pterodactyl\Models\Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Pterodactyl\Models\Egg, $this>
     */
    public function egg(): BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Pterodactyl\Models\Nest, $this>
     */
    public function nest(): BelongsTo
    {
        return $this->belongsTo(Nest::class);
    }

    /**
     * Path of the slot's file store relative to the server volume root. Only
     * ever used when the slot is inactive; the active slot's files live at the
     * volume root itself.
     */
    public function storagePath(): string
    {
        return GameSlotStorage::STORE_DIRECTORY . '/' . $this->storage_reference;
    }

    public function isSwitchable(): bool
    {
        return $this->state === self::STATE_NORMAL
            && $this->installation_status !== self::INSTALL_INSTALLING;
    }

    /**
     * Marks this slot as the active slot for the server. Must be called inside
     * a database transaction; the unique index on active_marker guarantees at
     * most one active slot per server even under concurrent writes.
     */
    public function markActive(): void
    {
        $this->forceFill([
            'is_active' => true,
            'active_marker' => $this->server_id,
            'last_activated_at' => now(),
        ])->save();
    }

    public function markInactive(): void
    {
        $this->forceFill([
            'is_active' => false,
            'active_marker' => null,
        ])->save();
    }
}
