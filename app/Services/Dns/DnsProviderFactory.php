<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Contracts\Dns\DnsProvider;
use Pterodactyl\Exceptions\Service\Dns\DnsProviderException;

class DnsProviderFactory
{
    public function __construct(private CloudflareDnsProvider $cloudflare)
    {
    }

    public function for(ManagedDomain $domain): DnsProvider
    {
        return match($domain->provider) {
            'cloudflare' => $this->cloudflare,
            default => throw new DnsProviderException('unsupported_provider', 'The configured DNS provider is not supported.'),
        };
    }
}
