<?php

namespace Pterodactyl\Tests\Integration\Services\Dns;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Contracts\Dns\DnsProvider;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Dns\DnsProviderFactory;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Dns\ManagedHostnameAvailabilityService;

class ManagedHostnameAvailabilityServiceTest extends IntegrationTestCase
{
    public function testChecksExactSrvAndWildcardProviderNames(): void
    {
        $domain = $this->domain();
        $fqdn = 'play.' . $domain->domain;
        $checked = [];
        $provider = $this->createMock(DnsProvider::class);
        $provider->expects($this->exactly(3))
            ->method('listRecords')
            ->willReturnCallback(function (ManagedDomain $actualDomain, string $name) use ($domain, &$checked) {
                $this->assertTrue($domain->is($actualDomain));
                $checked[] = $name;

                return [];
            });
        $factory = $this->createMock(DnsProviderFactory::class);
        $factory->expects($this->once())->method('for')->with($domain)->willReturn($provider);

        (new ManagedHostnameAvailabilityService($factory))->assertAvailable($domain, $fqdn);

        $this->assertSame([
            $fqdn,
            '_minecraft._tcp.' . $fqdn,
            '*.' . $domain->domain,
        ], $checked);
    }

    public function testProviderConflictIsRejectedBeforeCreation(): void
    {
        $domain = $this->domain();
        $fqdn = 'dynmap.' . $domain->domain;
        $provider = $this->createMock(DnsProvider::class);
        $provider->expects($this->once())
            ->method('listRecords')
            ->with($domain, $fqdn)
            ->willReturn([['id' => 'existing-record', 'type' => 'A']]);
        $factory = $this->createMock(DnsProviderFactory::class);
        $factory->method('for')->willReturn($provider);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('DNS already exists');
        (new ManagedHostnameAvailabilityService($factory))->assertAvailable($domain, $fqdn);
    }

    public function testPanelOwnedHostnameIsRejectedBeforeProviderLookup(): void
    {
        $server = $this->createServerModel();
        $domain = $this->domain();
        $fqdn = 'used.' . $domain->domain;
        $profileId = \Pterodactyl\Models\DnsServiceProfile::query()->where('slug', 'generic-tcp')->value('id');
        ManagedSubdomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'allocation_id' => $server->allocation_id,
            'managed_domain_id' => $domain->id,
            'dns_service_profile_id' => $profileId,
            'label' => 'used',
            'fqdn' => $fqdn,
            'routing_mode' => 'direct_dns',
            'detected_service' => 'Generic TCP service',
            'service_detection_source' => 'direct_dns_fallback',
            'status' => 'active',
            'desired_state_version' => 1,
            'public_target_type' => 'A',
            'public_target' => '1.1.1.1',
            'target_port' => $server->allocation->port,
            'connection_address' => $fqdn . ':25565',
            'desired_record_plan' => ['records' => []],
        ]);

        $factory = $this->createMock(DnsProviderFactory::class);
        $factory->expects($this->never())->method('for');

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('already in use');
        (new ManagedHostnameAvailabilityService($factory))->assertAvailable($domain, $fqdn);
    }

    private function domain(): ManagedDomain
    {
        $domain = 'test-' . strtolower(substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 12)) . '.example.com';

        return ManagedDomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => 'Example',
            'domain' => $domain,
            'provider' => 'cloudflare',
            'zone_id' => str_repeat('a', 32),
            'api_token' => 'token',
            'enabled' => true,
            'label_pattern' => '^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$',
            'last_provider_status' => 'healthy',
        ]);
    }
}
