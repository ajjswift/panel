<?php

namespace Pterodactyl\Services\ReverseProxy;

use Pterodactyl\Models\Node;
use Pterodactyl\Enum\DnsRoutingMode;
use Pterodactyl\Models\ManagedSubdomain;

/**
 * Builds the desired reverse-proxy route set for a node and pushes it to that
 * node's agent. The agent runs on the node itself, so every route forwards to a
 * local port; DNS is expected to point the hostname at the node's public IP.
 */
class ReverseProxyRouteSyncService
{
    public function __construct(private ReverseProxyAgentClient $client)
    {
    }

    /**
     * Push the full desired route set to the node's agent.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function sync(Node $node): void
    {
        if (!$node->reverse_proxy_enabled) {
            return;
        }

        $this->client->syncRoutes($node, $this->buildRoutes($node));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildRoutes(Node $node): array
    {
        $subdomains = ManagedSubdomain::query()
            ->whereNull('deleted_at')
            ->where('routing_mode', DnsRoutingMode::ReverseProxy->value)
            ->whereHas('server', fn ($q) => $q->where('node_id', $node->id))
            ->with(['allocation', 'server'])
            ->get();

        return $subdomains
            ->filter(fn (ManagedSubdomain $s) => $s->allocation !== null)
            ->map(fn (ManagedSubdomain $s) => [
                'id' => $s->uuid,
                'hostname' => $s->fqdn,
                // The public IP the hostname should resolve to (this node).
                'expected_ip' => $node->dns_target_ipv4 ?: $s->public_target,
                // The agent forwards to the service running locally on the node.
                'target_host' => '127.0.0.1',
                'target_port' => $s->target_port,
                'target_scheme' => 'http',
            ])
            ->values()
            ->all();
    }
}
