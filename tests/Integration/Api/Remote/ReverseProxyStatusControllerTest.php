<?php

namespace Pterodactyl\Tests\Integration\Api\Remote;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\ReverseProxy\ReverseProxyKeyService;

class ReverseProxyStatusControllerTest extends IntegrationTestCase
{
    private Server $server;

    public function setUp(): void
    {
        parent::setUp();

        $this->server = $this->createServerModel();
        $this->app->make(ReverseProxyKeyService::class)->rotate($this->server->node);
    }

    public function tearDown(): void
    {
        ManagedSubdomain::query()->forceDelete();
        ManagedDomain::query()->forceDelete();

        parent::tearDown();
    }

    public function testCallbackRequiresAValidAgentToken(): void
    {
        $this->withHeader('Authorization', 'Bearer wrong.token')
            ->postJson('/api/remote/proxy/status', ['agent' => ['healthy' => true], 'routes' => []])
            ->assertForbidden();
    }

    public function testAgentHealthAndRouteStatusAreRecorded(): void
    {
        $node = $this->server->node;
        $subdomain = $this->makeProxySubdomain();

        $token = $this->app->make(ReverseProxyKeyService::class)->callbackToken($node->refresh());

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/remote/proxy/status', [
                'agent' => ['version' => '1.2.3', 'healthy' => true],
                'routes' => [[
                    'id' => $subdomain->uuid,
                    'dns_status' => 'ok',
                    'cert_status' => 'issuing',
                    'proxy_status' => 'pending',
                    'sanitized_message' => 'Requesting certificate',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('received', 1);

        $node->refresh();
        $this->assertSame('online', $node->reverse_proxy_status);
        $this->assertSame('1.2.3', $node->reverse_proxy_agent_version);
        $this->assertNotNull($node->reverse_proxy_last_seen_at);

        $subdomain->refresh();
        $this->assertSame('ok', $subdomain->proxy_dns_status);
        $this->assertSame('issuing', $subdomain->proxy_cert_status);
        $this->assertSame('pending', $subdomain->proxy_status);
    }

    public function testAgentCannotUpdateAnotherNodesSubdomain(): void
    {
        $node = $this->server->node;
        // A subdomain owned by a server on a completely different node.
        $otherServer = $this->createServerModel();
        $foreign = $this->makeProxySubdomain($otherServer);

        $token = $this->app->make(ReverseProxyKeyService::class)->callbackToken($node->refresh());

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/remote/proxy/status', [
                'agent' => ['healthy' => true],
                'routes' => [[
                    'id' => $foreign->uuid,
                    'dns_status' => 'ok',
                    'cert_status' => 'active',
                    'proxy_status' => 'active',
                ]],
            ])
            ->assertOk();

        // The foreign subdomain must be untouched.
        $foreign->refresh();
        $this->assertNull($foreign->proxy_status);
    }

    private function makeProxySubdomain(?Server $server = null): ManagedSubdomain
    {
        $server = $server ?? $this->server;
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

        return ManagedSubdomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'allocation_id' => $server->allocation_id,
            'managed_domain_id' => $domain->id,
            'dns_service_profile_id' => null,
            'label' => 'web',
            'fqdn' => 'web.' . $domain->domain,
            'routing_mode' => 'reverse_proxy',
            'detected_service' => 'Web',
            'service_detection_source' => 'generic',
            'status' => 'pending',
            'desired_state_version' => 1,
            'public_target_type' => 'A',
            'public_target' => '203.0.113.10',
            'target_port' => 8080,
            'connection_address' => 'web.' . $domain->domain,
            'desired_record_plan' => ['records' => [], 'access_method' => 'proxy'],
        ]);
    }
}
