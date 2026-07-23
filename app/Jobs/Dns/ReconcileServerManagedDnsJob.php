<?php

namespace Pterodactyl\Jobs\Dns;

use Illuminate\Bus\Queueable;
use Pterodactyl\Models\Server;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\Services\Dns\ManagedSubdomainReconciliationService;

class ReconcileServerManagedDnsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $serverId, public bool $checkProvider = false)
    {
    }

    public function handle(ManagedSubdomainReconciliationService $service): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server && $server->managedSubdomains()->whereNull('deleted_at')->exists()) {
            $service->reconcile($server, $this->checkProvider);
        }
    }
}
