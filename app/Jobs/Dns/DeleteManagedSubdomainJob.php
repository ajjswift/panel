<?php

namespace Pterodactyl\Jobs\Dns;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Pterodactyl\Models\ManagedSubdomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\Services\Dns\ManagedDnsSynchronizer;
use Pterodactyl\Exceptions\Service\Dns\DnsProviderException;

class DeleteManagedSubdomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public int $managedSubdomainId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(ManagedDnsSynchronizer $synchronizer): void
    {
        $managed = ManagedSubdomain::query()->find($this->managedSubdomainId);
        if (!$managed || $managed->deleted_at) {
            return;
        }

        $lock = Cache::lock('managed-dns:fqdn:' . $managed->fqdn, 180);
        if (!$lock->get()) {
            $this->release(15);

            return;
        }

        $wasProxy = $managed->routing_mode === \Pterodactyl\Enum\DnsRoutingMode::ReverseProxy;
        $nodeId = $managed->server->node_id;

        try {
            try {
                $synchronizer->delete($managed);
            } catch (DnsProviderException $exception) {
                if ($exception->providerErrorCode === 'provider_credentials_rejected') {
                    $this->fail($exception);

                    return;
                }

                throw $exception;
            }

            // Re-push the node's route set (now without this address) so the
            // agent tears down its proxy and certificate.
            if ($wasProxy) {
                \Pterodactyl\Jobs\ReverseProxy\SyncNodeReverseProxyJob::dispatch($nodeId);
            }
        } finally {
            $lock->release();
        }
    }
}
