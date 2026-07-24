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
