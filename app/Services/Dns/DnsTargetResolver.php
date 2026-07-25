<?php

namespace Pterodactyl\Services\Dns;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Dns\Results\DnsTarget;

class DnsTargetResolver
{
    public function resolve(Allocation $allocation, ManagedDomain $domain): DnsTarget
    {
        $allocation->loadMissing('node');

        if ($this->isPublicIp($allocation->ip)) {
            return new DnsTarget(
                filter_var($allocation->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A',
                $allocation->ip,
                'allocation_ip',
            );
        }

        foreach ([
            ['A', $allocation->node->dns_target_ipv4, 'node_ipv4'],
            ['AAAA', $allocation->node->dns_target_ipv6, 'node_ipv6'],
            ['CNAME', $allocation->node->dns_target_hostname, 'node_hostname'],
            ['A', $domain->target_ipv4, 'domain_ipv4'],
            ['AAAA', $domain->target_ipv6, 'domain_ipv6'],
            ['CNAME', $domain->cname_target, 'domain_cname'],
        ] as [$type, $value, $source]) {
            if (!$value) {
                continue;
            }

            if (($type === 'CNAME' && $this->isSafeHostname($value)) || ($type !== 'CNAME' && $this->isPublicIp($value))) {
                return new DnsTarget($type, rtrim($value, '.'), $source);
            }
        }

        throw new DisplayException('No safe public DNS target is configured for this allocation.');
    }

    /**
     * Ordered list of hosts the panel should try when probing what service is
     * actually running on an allocation. Detection must connect to where the
     * service really listens — the allocation's own IP first (correct when the
     * panel and node are co-located, or the allocation is a public IP), then the
     * node's public address (correct when the game port is exposed to the world
     * behind a private bind). Duplicates and unroutable placeholders are dropped.
     *
     * @return string[]
     */
    public function resolveProbeCandidates(Allocation $allocation): array
    {
        $allocation->loadMissing('node');
        $node = $allocation->node;

        $candidates = [
            $allocation->ip,
            $node->dns_target_ipv4,
            $node->dns_target_ipv6,
            $node->dns_target_hostname,
            $node->fqdn,
        ];

        $seen = [];
        foreach ($candidates as $candidate) {
            $host = is_string($candidate) ? strtolower(trim(rtrim($candidate, '.'))) : '';
            // 0.0.0.0 / :: are "listen on everything" placeholders, not a
            // reachable address to connect to.
            if ($host === '' || $host === '0.0.0.0' || $host === '::' || isset($seen[$host])) {
                continue;
            }
            $seen[$host] = true;
        }

        return array_keys($seen);
    }

    /**
     * Resolve the public address of the reverse-proxy agent on this allocation's
     * node. A null result means this node cannot currently host proxy routes.
     */
    public function resolveForReverseProxy(Allocation $allocation): ?DnsTarget
    {
        $allocation->loadMissing('node');
        $node = $allocation->node;

        if (!$node->reverse_proxy_enabled || !$node->dns_target_ipv4 || !$this->isPublicIp($node->dns_target_ipv4)) {
            return null;
        }

        return new DnsTarget('A', $node->dns_target_ipv4, 'node_reverse_proxy_ipv4');
    }

    /**
     * Resolve an existing hostname suitable as an SRV target. SRV records
     * cannot target an IP address, and using the managed hostname itself would
     * require creating the A record that SRV routing is intended to avoid.
     */
    public function resolveForSrv(Allocation $allocation): ?string
    {
        $allocation->loadMissing('node');

        foreach ([$allocation->node->dns_target_hostname, $allocation->node->fqdn] as $hostname) {
            if ($hostname && $this->isSafeHostname($hostname)) {
                return strtolower(rtrim($hostname, '.'));
            }
        }

        return null;
    }

    public function isPublicIp(string $value): bool
    {
        return filter_var(
            $value,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    public function isSafeHostname(string $value): bool
    {
        $hostname = strtolower(rtrim(trim($value), '.'));

        return strlen($hostname) <= 253
            && !filter_var($hostname, FILTER_VALIDATE_IP)
            && !in_array($hostname, ['localhost', 'localhost.localdomain'], true)
            && !str_ends_with($hostname, '.local')
            && !str_ends_with($hostname, '.internal')
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/', $hostname) === 1;
    }
}
