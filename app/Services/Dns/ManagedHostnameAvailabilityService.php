<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Exceptions\DisplayException;

/**
 * Rejects a managed hostname before service probing or local state creation
 * when either the panel or DNS provider already knows about it.
 */
class ManagedHostnameAvailabilityService
{
    public function __construct(private DnsProviderFactory $providerFactory)
    {
    }

    public function assertAvailable(ManagedDomain $domain, string $fqdn): void
    {
        if (ManagedSubdomain::query()->where('fqdn', $fqdn)->exists()) {
            throw new DisplayException('This hostname is already in use. Choose another name.');
        }

        $provider = $this->providerFactory->for($domain);
        $parent = strtolower(rtrim($domain->domain, '.'));
        $names = [
            strtolower(rtrim($fqdn, '.')),
            sprintf('_minecraft._tcp.%s', strtolower(rtrim($fqdn, '.'))),
            '*.' . $parent,
        ];

        foreach ($names as $name) {
            if ($provider->listRecords($domain, $name) !== []) {
                throw new DisplayException('DNS already exists for this hostname. Choose another name or remove the existing DNS record first.');
            }
        }
    }
}
