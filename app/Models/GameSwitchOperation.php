<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int|null $source_slot_id
 * @property int $destination_slot_id
 * @property int|null $requested_by
 * @property string $state
 * @property string $current_stage
 * @property string $checkpoint
 * @property int|null $lock_marker
 * @property string|null $previous_power_state
 * @property bool $restore_power
 * @property int $retry_count
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array|null $internal_error_context
 * @property string|null $rollback_state
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property Server $server
 * @property GameSlot|null $sourceSlot
 * @property GameSlot $destinationSlot
 * @property User|null $user
 */
class GameSwitchOperation extends Model
{
    use HasFactory;

    public const RESOURCE_NAME = 'game_switch_operation';

    public const STATE_PENDING = 'pending';
    public const STATE_RUNNING = 'running';
    public const STATE_COMPLETED = 'completed';
    // The switch failed but the previous slot was restored successfully.
    public const STATE_FAILED_ROLLED_BACK = 'failed_rolled_back';
    // The switch failed and automatic rollback also failed; an administrator
    // must intervene through the recovery tooling.
    public const STATE_FAILED_REQUIRES_ACTION = 'failed_requires_action';

    // Durable checkpoints. Each is only written after the work it describes has
    // fully completed, so recovery can resume from the last checkpoint without
    // repeating non-idempotent work.
    public const CHECKPOINT_CREATED = 'created';
    public const CHECKPOINT_SERVER_STOPPED = 'server_stopped';
    public const CHECKPOINT_SOURCE_SAVED = 'source_saved';
    public const CHECKPOINT_DESTINATION_PREPARED = 'destination_prepared';
    public const CHECKPOINT_CONFIG_UPDATED = 'config_updated';
    public const CHECKPOINT_WINGS_SYNCED = 'wings_synced';
    public const CHECKPOINT_INSTALLED = 'installed';
    public const CHECKPOINT_COMMITTED = 'committed';

    // User-facing stages surfaced through the status endpoint.
    public const STAGE_PENDING = 'pending';
    public const STAGE_STOPPING = 'stopping_server';
    public const STAGE_SAVING_SOURCE = 'saving_source_files';
    public const STAGE_PREPARING_DESTINATION = 'preparing_destination_files';
    public const STAGE_UPDATING_CONFIGURATION = 'updating_configuration';
    public const STAGE_SYNCING = 'syncing_with_node';
    public const STAGE_INSTALLING = 'installing';
    public const STAGE_VALIDATING = 'validating';
    public const STAGE_RESTORING_POWER = 'restoring_power';
    public const STAGE_COMPLETED = 'completed';
    public const STAGE_ROLLING_BACK = 'rolling_back';
    public const STAGE_FAILED = 'failed';

    public const ROLLBACK_NONE = null;
    public const ROLLBACK_SUCCEEDED = 'succeeded';
    public const ROLLBACK_FAILED = 'failed';

    public const ACTIVE_STATES = [self::STATE_PENDING, self::STATE_RUNNING];

    protected $table = 'game_switch_operations';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'server_id' => 'integer',
        'source_slot_id' => 'integer',
        'destination_slot_id' => 'integer',
        'requested_by' => 'integer',
        'lock_marker' => 'integer',
        'restore_power' => 'boolean',
        'retry_count' => 'integer',
        'internal_error_context' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid',
        'server_id' => 'required|numeric|exists:servers,id',
        'destination_slot_id' => 'required|numeric|exists:game_slots,id',
        'state' => 'required|string',
        'current_stage' => 'required|string',
        'checkpoint' => 'required|string',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Pterodactyl\Models\Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Pterodactyl\Models\GameSlot, $this>
     */
    public function sourceSlot(): BelongsTo
    {
        return $this->belongsTo(GameSlot::class, 'source_slot_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Pterodactyl\Models\GameSlot, $this>
     */
    public function destinationSlot(): BelongsTo
    {
        return $this->belongsTo(GameSlot::class, 'destination_slot_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Pterodactyl\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isTerminal(): bool
    {
        return !in_array($this->state, self::ACTIVE_STATES, true);
    }

    /**
     * Advance the durable checkpoint and user-facing stage. Persisted
     * immediately so a worker crash can resume from the correct point.
     */
    public function advance(string $checkpoint, string $stage): void
    {
        $this->forceFill([
            'checkpoint' => $checkpoint,
            'current_stage' => $stage,
        ])->save();
    }

    public function setStage(string $stage): void
    {
        if ($this->current_stage !== $stage) {
            $this->forceFill(['current_stage' => $stage])->save();
        }
    }
}
