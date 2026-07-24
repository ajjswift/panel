<?php

namespace Pterodactyl\Jobs\ReverseProxy;

use Pterodactyl\Models\Node;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Pterodactyl\Services\ReverseProxy\ReverseProxyRouteSyncService;

/**
 * Pushes a node's full desired reverse-proxy route set to its agent. Coalesced
 * per node so a burst of subdomain changes results in a single sync.
 */
class SyncNodeReverseProxyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 60];

    public function __construct(public int $nodeId)
    {
    }

    public function uniqueId(): string
    {
        return 'reverse-proxy-sync:' . $this->nodeId;
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(ReverseProxyRouteSyncService $service): void
    {
        $node = Node::query()->find($this->nodeId);
        if (!$node || !$node->reverse_proxy_enabled) {
            return;
        }

        $service->sync($node);
    }

    public function failed(?\Throwable $exception = null): void
    {
        Log::warning('Failed to sync reverse-proxy routes to node agent.', [
            'node_id' => $this->nodeId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
