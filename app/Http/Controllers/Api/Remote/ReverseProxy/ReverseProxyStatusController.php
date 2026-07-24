<?php

namespace Pterodactyl\Http\Controllers\Api\Remote\ReverseProxy;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Pterodactyl\Models\Node;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Receives status callbacks from a node's reverse-proxy agent and records agent
 * health plus per-route (per-subdomain) DNS/certificate/proxy status so it can
 * be shown to users. The node is resolved by AuthenticateReverseProxyAgent.
 */
class ReverseProxyStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Node $node */
        $node = $request->attributes->get('node');

        $data = $request->validate([
            'agent' => 'required|array',
            'agent.version' => 'nullable|string|max:64',
            'agent.healthy' => 'required|boolean',
            'routes' => 'present|array',
            'routes.*.id' => 'required|uuid',
            'routes.*.dns_status' => 'required|string|max:32',
            'routes.*.cert_status' => 'required|string|max:32',
            'routes.*.proxy_status' => 'required|string|max:32',
            'routes.*.cert_expires_at' => 'nullable|date',
            'routes.*.last_error_code' => 'nullable|string|max:64',
            'routes.*.sanitized_message' => 'nullable|string|max:500',
        ]);

        $node->forceFill([
            'reverse_proxy_status' => $data['agent']['healthy'] ? 'online' : 'unhealthy',
            'reverse_proxy_agent_version' => $data['agent']['version'] ?? $node->reverse_proxy_agent_version,
            'reverse_proxy_last_seen_at' => Carbon::now(),
        ])->save();

        foreach ($data['routes'] as $route) {
            // Only update subdomains that belong to a server on this node — the
            // agent must never be able to mutate another node's records.
            ManagedSubdomain::query()
                ->where('uuid', $route['id'])
                ->whereHas('server', fn ($q) => $q->where('node_id', $node->id))
                ->update([
                    'proxy_dns_status' => $route['dns_status'],
                    'proxy_cert_status' => $route['cert_status'],
                    'proxy_status' => $route['proxy_status'],
                    'proxy_cert_expires_at' => $route['cert_expires_at'] ?? null,
                    'proxy_reported_at' => Carbon::now(),
                    'sanitized_error_message' => $route['sanitized_message'] ?? null,
                    'last_error_code' => $route['last_error_code'] ?? null,
                ]);
        }

        return new JsonResponse(['received' => count($data['routes'])]);
    }
}
