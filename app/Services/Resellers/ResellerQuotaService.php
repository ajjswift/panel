<?php

namespace Pterodactyl\Services\Resellers;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Reseller;
use Pterodactyl\Exceptions\DisplayException;

/**
 * Accounting for a reseller's resource pool.
 *
 * Across every dimension: -1 means unlimited and 0 means none. That matches the
 * existing convention on `servers.allocation_limit` and friends, so a reseller
 * pool reads the same way as the per-server limits it hands out.
 */
class ResellerQuotaService
{
    /**
     * Human names used in the "you've run out of X" message.
     */
    private const LABELS = [
        'memory' => 'memory',
        'disk' => 'disk space',
        'cpu' => 'CPU',
        'server_limit' => 'servers',
        'user_limit' => 'users',
        'database_limit' => 'databases',
        'allocation_limit' => 'additional ports',
        'backup_limit' => 'backups',
    ];

    /**
     * Dimensions expressed in MiB, so the failure message can say "2048 MiB"
     * rather than a bare number.
     */
    private const MEBIBYTE_DIMENSIONS = ['memory', 'disk'];

    /** @var array<int, ResellerUsage> */
    private array $cache = [];

    /**
     * Current consumption for a reseller. Memoised for the life of the request
     * — a single page renders the dashboard bars and validates a form off the
     * same numbers.
     */
    public function usage(Reseller $reseller, bool $fresh = false): ResellerUsage
    {
        if ($fresh) {
            unset($this->cache[$reseller->id]);
        }

        return $this->cache[$reseller->id] ??= $this->calculate($reseller);
    }

    /**
     * Assert that the reseller can afford the given resources, throwing a
     * user-facing error naming the first dimension that doesn't fit.
     *
     * @param array<string, int|null> $resources keyed by *server* column
     *                                           (memory, disk, cpu, database_limit,
     *                                           allocation_limit, backup_limit)
     * @param Server|null $ignoring a server whose current consumption should be
     *                              refunded before checking — pass the server
     *                              being edited so it isn't billed twice
     *
     * @throws DisplayException
     */
    public function assertCanAllocateServer(Reseller $reseller, array $resources, ?Server $ignoring = null): void
    {
        $usage = $this->usage($reseller);

        // A new server consumes one slot; an edit does not.
        $requested = ['server_limit' => is_null($ignoring) ? 1 : 0];

        foreach (['memory', 'disk', 'cpu', 'database_limit', 'allocation_limit', 'backup_limit'] as $dimension) {
            $requested[$dimension] = (int) Arr::get($resources, $dimension, 0);

            if (!is_null($ignoring)) {
                $requested[$dimension] -= (int) ($ignoring->{$dimension} ?? 0);
            }
        }

        $this->assertFits($usage, $requested);
    }

    /**
     * @throws DisplayException
     */
    public function assertCanCreateUser(Reseller $reseller): void
    {
        $this->assertFits($this->usage($reseller), ['user_limit' => 1]);
    }

    /**
     * @param array<string, int> $requested delta per dimension; negative values
     *                                      (an edit that shrinks a server) are
     *                                      always allowed
     *
     * @throws DisplayException
     */
    private function assertFits(ResellerUsage $usage, array $requested): void
    {
        foreach ($requested as $dimension => $amount) {
            if ($amount <= 0 || $usage->isUnlimited($dimension)) {
                continue;
            }

            $remaining = $usage->remaining($dimension);
            if ($amount > $remaining) {
                throw new DisplayException($this->message($dimension, $amount, $remaining));
            }
        }
    }

    private function message(string $dimension, int $requested, int $remaining): string
    {
        $label = self::LABELS[$dimension] ?? $dimension;
        $unit = in_array($dimension, self::MEBIBYTE_DIMENSIONS, true) ? ' MiB' : '';

        if ($remaining === 0) {
            return sprintf('This would exceed your %s allowance — you have none remaining.', $label);
        }

        return sprintf(
            'This would exceed your %s allowance: %s%s requested, %s%s remaining.',
            $label,
            $requested,
            $unit,
            $remaining,
            $unit,
        );
    }

    private function calculate(Reseller $reseller): ResellerUsage
    {
        // Aliases are prefixed because "databases" is a reserved word in MariaDB
        // and an unquoted alias of that name is a syntax error.
        /** @var object{sum_memory: int|null, sum_disk: int|null, sum_cpu: int|null, sum_servers: int, sum_databases: int|null, sum_allocations: int|null, sum_backups: int|null} $totals */
        $totals = Server::query()
            ->forReseller($reseller)
            ->selectRaw('COALESCE(SUM(memory), 0) as sum_memory')
            ->selectRaw('COALESCE(SUM(disk), 0) as sum_disk')
            ->selectRaw('COALESCE(SUM(cpu), 0) as sum_cpu')
            ->selectRaw('COUNT(*) as sum_servers')
            ->selectRaw('COALESCE(SUM(database_limit), 0) as sum_databases')
            ->selectRaw('COALESCE(SUM(allocation_limit), 0) as sum_allocations')
            ->selectRaw('COALESCE(SUM(backup_limit), 0) as sum_backups')
            ->first();

        $used = [
            'memory' => (int) $totals->sum_memory,
            'disk' => (int) $totals->sum_disk,
            'cpu' => (int) $totals->sum_cpu,
            'server_limit' => (int) $totals->sum_servers,
            'database_limit' => (int) $totals->sum_databases,
            'allocation_limit' => (int) $totals->sum_allocations,
            'backup_limit' => (int) $totals->sum_backups,
            'user_limit' => $reseller->tenants()->count(),
        ];

        $limits = [];
        foreach (Reseller::QUOTA_DIMENSIONS as $dimension) {
            $limits[$dimension] = (int) $reseller->{$dimension};
        }

        return new ResellerUsage($used, $limits);
    }
}
