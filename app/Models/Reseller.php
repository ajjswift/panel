<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property bool $enabled
 * @property int $memory
 * @property int $disk
 * @property int $cpu
 * @property int $server_limit
 * @property int $user_limit
 * @property int $database_limit
 * @property int $allocation_limit
 * @property int $backup_limit
 * @property string|null $app_name
 * @property string|null $brand_color
 * @property string|null $accent_color
 * @property string|null $logo_path
 * @property string|null $favicon_path
 * @property \Illuminate\Support\Carbon|null $theme_updated_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property User $owner
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\User[] $tenants
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\Node[] $nodes
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\ResellerDomain[] $domains
 */
class Reseller extends Model
{
    /** @use HasFactory<\Database\Factories\ResellerFactory> */
    use HasFactory;

    /**
     * Every quota dimension uses this sentinel for "no limit". Zero means the
     * reseller may not allocate any of that resource at all.
     */
    public const UNLIMITED = -1;

    /**
     * The quota columns making up a reseller's pool. Canonical list — the admin
     * form, the factory and ResellerQuotaService all iterate this.
     */
    public const QUOTA_DIMENSIONS = [
        'memory',
        'disk',
        'cpu',
        'server_limit',
        'user_limit',
        'database_limit',
        'allocation_limit',
        'backup_limit',
    ];

    public const RESOURCE_NAME = 'reseller';

    protected $table = 'resellers';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'user_id' => 'integer',
        'enabled' => 'boolean',
        'memory' => 'integer',
        'disk' => 'integer',
        'cpu' => 'integer',
        'server_limit' => 'integer',
        'user_limit' => 'integer',
        'database_limit' => 'integer',
        'allocation_limit' => 'integer',
        'backup_limit' => 'integer',
        'theme_updated_at' => 'datetime',
    ];

    public static array $validationRules = [
        'uuid' => 'required|uuid|unique:resellers,uuid',
        'user_id' => 'required|integer|exists:users,id',
        'name' => 'required|string|max:191',
        'enabled' => 'boolean',
        'memory' => 'required|integer|min:-1',
        'disk' => 'required|integer|min:-1',
        'cpu' => 'required|integer|min:-1',
        'server_limit' => 'required|integer|min:-1',
        'user_limit' => 'required|integer|min:-1',
        'database_limit' => 'required|integer|min:-1',
        'allocation_limit' => 'required|integer|min:-1',
        'backup_limit' => 'required|integer|min:-1',
        'app_name' => 'nullable|string|max:60',
        'brand_color' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
        'accent_color' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
        'logo_path' => 'nullable|string|max:191',
        'favicon_path' => 'nullable|string|max:191',
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<User, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(User::class, 'reseller_id');
    }

    /** @return BelongsToMany<Node, $this> */
    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'reseller_nodes');
    }

    /** @return HasMany<ResellerDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(ResellerDomain::class);
    }

    /**
     * Every server owned by one of this reseller's tenants.
     *
     * Tenancy is derived rather than denormalised — `Server::forReseller()` is
     * the single definition of "belongs to this reseller".
     */
    public function servers(): \Illuminate\Database\Eloquent\Builder
    {
        return Server::query()->forReseller($this);
    }

    /**
     * Whether the reseller has any branding worth injecting into the page.
     */
    public function hasBranding(): bool
    {
        return !empty($this->brand_color)
            || !empty($this->accent_color)
            || !empty($this->app_name)
            || !empty($this->logo_path);
    }
}
