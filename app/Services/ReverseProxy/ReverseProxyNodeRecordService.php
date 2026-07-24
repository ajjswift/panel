<?php

namespace Pterodactyl\Services\ReverseProxy;

use Pterodactyl\Models\Node;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Dns\DnsTargetResolver;
use Pterodactyl\Services\Dns\CloudflareDnsProvider;

/**
 * Ensures the DNS A record for a node's own agent hostname
 * ({slug}.{base-domain}) points at the node's public IP, so the panel and the
 * ACME challenge can reach the agent by name.
 */
class ReverseProxyNodeRecordService
{
    public function __construct(
        private CloudflareDnsProvider $cloudflare,
        private DnsTargetResolver $targetResolver,
    ) {
    }

    /**
     * @throws DisplayException
     */
    public function ensure(Node $node): void
    {
        $domain = $node->reverseProxyBaseDomain;
        $hostname = $node->reverseProxyHostname();
        if (!$domain || !$hostname) {
            throw new DisplayException('Choose a base domain for this node first.');
        }

        $ip = $node->dns_target_ipv4;
        if (!$ip || !$this->targetResolver->isPublicIp($ip)) {
            throw new DisplayException('Set a public "DNS Target IPv4" for this node before enabling the reverse proxy — it is where the agent hostname must point.');
        }

        $record = ['type' => 'A', 'name' => $hostname, 'content' => $ip, 'ttl' => $domain->ttl];
        $existing = collect($this->cloudflare->listRecords($domain, $hostname))
            ->firstWhere('type', 'A');

        if ($existing) {
            $this->cloudflare->updateRecord($domain, $existing['id'], $record);
        } else {
            $this->cloudflare->createRecord($domain, $record);
        }
    }
}
