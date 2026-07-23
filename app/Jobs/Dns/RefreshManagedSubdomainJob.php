<?php

namespace Pterodactyl\Jobs\Dns;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Pterodactyl\Models\ManagedSubdomain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\Services\Dns\ManagedSubdomainReconciliationService;

class RefreshManagedSubdomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $managedSubdomainId)
    {
    }

    public function handle(ManagedSubdomainReconciliationService $service): void
    {
        $managed = ManagedSubdomain::query()->whereNull('deleted_at')->find($this->managedSubdomainId);
        if ($managed) {
            $lock = Cache::lock('managed-dns:fqdn:' . $managed->fqdn, 60);
            if (!$lock->get()) {
                $this->release(10);

                return;
            }

            try {
                $service->checkDrift($managed);
            } finally {
                $lock->release();
            }
        }
    }
}
