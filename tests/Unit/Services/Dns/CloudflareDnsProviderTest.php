<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use Pterodactyl\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Services\Dns\CloudflareDnsProvider;
use Pterodactyl\Exceptions\Service\Dns\DnsProviderException;

class CloudflareDnsProviderTest extends TestCase
{
    public function testItDiscoversAnActiveZoneByExactDomainName(): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake([
            '*' => $http->response([
                'success' => true,
                'result' => [[
                    'id' => str_repeat('b', 32),
                    'name' => 'example.com',
                    'status' => 'active',
                ]],
            ], 200),
        ]);

        $zone = (new CloudflareDnsProvider($http))->discoverZone($this->domain());

        $this->assertSame(str_repeat('b', 32), $zone['id']);
        $this->assertSame('example.com', $zone['name']);
        $http->assertSent(fn ($request) => str_contains($request->url(), '/zones?')
            && str_contains($request->url(), 'name=example.com')
            && str_contains($request->url(), 'status=active'));
    }

    public function testItRejectsAZoneDiscoveryWithoutAnExactActiveMatch(): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake([
            '*' => $http->response([
                'success' => true,
                'result' => [[
                    'id' => str_repeat('b', 32),
                    'name' => 'other.example.com',
                    'status' => 'active',
                ]],
            ], 200),
        ]);

        $this->expectException(DnsProviderException::class);
        $this->expectExceptionMessage('No active Cloudflare zone matching that domain was found.');

        (new CloudflareDnsProvider($http))->discoverZone($this->domain());
    }

    public function testSrvPayloadIsUnproxiedAndContainsProtocolFields(): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake([
            '*' => $http->response(['success' => true, 'result' => ['id' => 'record-id']], 200),
        ]);

        $provider = new CloudflareDnsProvider($http);
        $provider->createRecord($this->domain(), [
            'type' => 'SRV',
            'name' => '_minecraft._tcp.play.example.com',
            'ttl' => 300,
            'priority' => 0,
            'weight' => 5,
            'port' => 25572,
            'target' => 'play.example.com',
        ]);

        $http->assertSent(function ($request) {
            return $request['type'] === 'SRV'
                && $request['proxied'] === false
                && $request['data']['port'] === 25572
                && $request['data']['target'] === 'play.example.com.';
        });
    }

    public function testProviderErrorsAreSanitized(): void
    {
        $http = new Factory();
        $http->preventStrayRequests();
        $http->fake([
            '*' => $http->response([
                'success' => false,
                'errors' => [['code' => 9109, 'message' => 'raw provider detail containing a secret']],
            ], 403),
        ]);

        try {
            (new CloudflareDnsProvider($http))->listRecords($this->domain(), 'play.example.com');
            $this->fail('Expected a provider exception.');
        } catch (DnsProviderException $exception) {
            $this->assertSame('provider_credentials_rejected', $exception->providerErrorCode);
            $this->assertSame('Cloudflare rejected the configured credentials.', $exception->getMessage());
            $this->assertStringNotContainsString('raw provider detail', $exception->getMessage());
        }
    }

    private function domain(): ManagedDomain
    {
        $domain = new ManagedDomain();
        $domain->forceFill([
            'domain' => 'example.com',
            'zone_id' => str_repeat('a', 32),
            'api_token' => 'scoped-token',
        ]);

        return $domain;
    }
}
