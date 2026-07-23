<?php

namespace Pterodactyl\Jobs\GameSlots;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

/**
 * Refreshes cached per-slot disk usage for a server. The active slot's usage is
 * derived from the node's reported total volume usage minus the cached sizes of
 * the inactive stores; inactive-slot sizes are only refreshed when a switch
 * stashes their files, so this job stays cheap and never walks directory trees
 * during a page request. Rate-limited to at most once per few minutes per
 * server via a cache lock.
 */
class ScanGameSlotDiskUsageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $serverId)
    {
    }

    public function uniqueId(): string
    {
        return 'gameslot-disk-scan:' . $this->serverId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(DaemonServerRepository $serverRepository): void
    {
        $server = Server::query()->with('gameSlots')->find($this->serverId);
        if (!$server || !$server->usesGameSlots()) {
            return;
        }

        // Cheap throttle so repeated visits do not hammer the node.
        $lock = Cache::lock('gameslot-disk-scan-lock:' . $server->id, 240);
        if (!$lock->get()) {
            return;
        }

        try {
            $details = $serverRepository->setServer($server)->getDetails();
            $total = (int) ($details['utilization']['disk_bytes'] ?? 0);

            $inactive = (int) $server->gameSlots()->where('is_active', false)->sum('disk_usage_bytes');
            $activeUsage = max(0, $total - $inactive);

            $active = $server->activeGameSlot()->first();
            $active?->forceFill([
                'disk_usage_bytes' => $activeUsage,
                'disk_scanned_at' => Carbon::now(),
            ])->save();
        } catch (\Throwable $exception) {
            // A disk scan is best-effort. An unreachable node or transient
            // failure must never surface as an error; cached values remain in
            // place and the next scan will refresh them.
            Log::debug('Game slot disk scan skipped.', [
                'server' => $server->uuid,
                'exception' => $exception->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }
}
