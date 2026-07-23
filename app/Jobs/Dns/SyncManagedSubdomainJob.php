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

class SyncManagedSubdomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public int $managedSubdomainId, public int $desiredStateVersion)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(ManagedDnsSynchronizer $synchronizer): void
    {
        $managed = ManagedSubdomain::query()->find($this->managedSubdomainId);
        if (!$managed) {
            return;
        }

        $lock = Cache::lock('managed-dns:fqdn:' . $managed->fqdn, 180);
        if (!$lock->get()) {
            $this->release(15);

            return;
        }

        try {
            try {
                $synchronizer->synchronize($managed, $this->desiredStateVersion);
            } catch (DnsProviderException $exception) {
                if (in_array($exception->providerErrorCode, [
                    'record_conflict',
                    'provider_record_conflict',
                    'provider_credentials_rejected',
                    'zone_mismatch',
                ], true)) {
                    $this->fail($exception);

                    return;
                }

                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }
}
