<?php

namespace Pterodactyl\Services\Resellers;

/**
 * A snapshot of what a reseller has actually consumed, keyed by the matching
 * quota column on the `resellers` table.
 */
class ResellerUsage
{
    /**
     * @param array<string, int> $used consumption keyed by reseller quota column
     * @param array<string, int> $limits the reseller's configured pool
     */
    public function __construct(
        private array $used,
        private array $limits,
    ) {
    }

    public function used(string $dimension): int
    {
        return $this->used[$dimension] ?? 0;
    }

    public function limit(string $dimension): int
    {
        return $this->limits[$dimension] ?? 0;
    }

    public function isUnlimited(string $dimension): bool
    {
        return $this->limit($dimension) < 0;
    }

    /**
     * Remaining headroom, or null when the dimension is unlimited. Never
     * negative — a pool reduced below current usage reads as "nothing left"
     * rather than as a nonsense negative.
     */
    public function remaining(string $dimension): ?int
    {
        if ($this->isUnlimited($dimension)) {
            return null;
        }

        return max(0, $this->limit($dimension) - $this->used($dimension));
    }

    /**
     * Consumption as a percentage, for the dashboard bars. Unlimited reads as
     * 0% (there is no meaningful bar to draw).
     */
    public function percentage(string $dimension): int
    {
        if ($this->isUnlimited($dimension) || $this->limit($dimension) <= 0) {
            return $this->used($dimension) > 0 ? 100 : 0;
        }

        return (int) min(100, round($this->used($dimension) / $this->limit($dimension) * 100));
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return $this->used;
    }
}
