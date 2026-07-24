<?php

namespace Pterodactyl\Tests\Integration\Services\ReverseProxy;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\ReverseProxy\ReverseProxyAgentClient;
use Pterodactyl\Services\ReverseProxy\ReverseProxyRouteSyncService;

class ReverseProxyRouteSyncServiceTest extends IntegrationTestCase
{
    public function testBuildsAnHttpRouteFromTheManagedSubdomainAndAllocationPort(): void
    {
        $server = $this->createServerModel();
        $server->node->forceFill([
            'reverse_proxy_enabled' => true,
            'dns_target_ipv4' => '203.0.113.10',
        ])->saveOrFail();
        $domain = ManagedDomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => 'Proxy ' . Str::random(4),
            'domain' => Str::lower(Str::random(8)) . '.example.com',
            'provider' => 'cloudflare',
            'zone_id' => str_repeat('a', 32),
            'api_token' => Crypt::encrypt('token'),
            'enabled' => true,
            'ttl' => 300,
            'label_pattern' => '^[a-z0-9-]+$',
        ]);
        $managed = ManagedSubdomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'allocation_id' => $server->allocation_id,
            'managed_domain_id' => $domain->id,
            'dns_service_profile_id' => null,
            'label' => 'dynmap',
            'fqdn' => 'dynmap.' . $domain->domain,
            'routing_mode' => 'reverse_proxy',
            'detected_service' => 'HTTP website',
            'service_detection_source' => 'http_probe',
            'status' => 'pending',
            'desired_state_version' => 1,
            'public_target_type' => 'A',
            'public_target' => '203.0.113.10',
            'target_port' => 8123,
            'connection_address' => 'dynmap.' . $domain->domain,
            'desired_record_plan' => [
                'records' => [],
                'access_method' => 'proxy',
                'proxy_target_scheme' => 'http',
            ],
        ]);

        $service = new ReverseProxyRouteSyncService($this->createMock(ReverseProxyAgentClient::class));

        $this->assertSame([[
            'id' => $managed->uuid,
            'hostname' => $managed->fqdn,
            'expected_ip' => '203.0.113.10',
            'target_host' => '127.0.0.1',
            'target_port' => 8123,
            'target_scheme' => 'http',
        ]], $service->buildRoutes($server->node->fresh()));
    }
}
