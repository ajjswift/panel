<?php

namespace Pterodactyl\Console\Commands\Maintenance;

use Pterodactyl\Models\Server;
use Illuminate\Console\Command;
use Pterodactyl\Jobs\Dns\ReconcileServerManagedDnsJob;

class ReconcileManagedDnsCommand extends Command
{
    protected $signature = 'p:maintenance:reconcile-managed-dns';

    protected $description = 'Queues drift and lifecycle reconciliation for servers with managed DNS hostnames.';

    public function handle(): int
    {
        Server::query()
            ->whereHas('managedSubdomains', fn ($query) => $query->whereNull('deleted_at'))
            ->select('id')
            ->chunkById(100, function ($servers) {
                foreach ($servers as $server) {
                    ReconcileServerManagedDnsJob::dispatch($server->id, true);
                }
            });

        return self::SUCCESS;
    }
}
