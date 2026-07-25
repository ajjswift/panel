<?php

namespace Pterodactyl\Tests\Integration\Services\Dns;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedDnsRecord;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Contracts\Dns\DnsProvider;
use Pterodactyl\Services\Dns\DnsProviderFactory;
use Pterodactyl\Services\Dns\ManagedDnsSynchronizer;
use Pterodactyl\Services\Dns\SubdomainPolicyResolver;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

class ManagedDnsSynchronizerDeleteTest extends IntegrationTestCase
{
    public function testSuccessfulProviderDeletionRemovesLocalOwnershipRecords(): void
    {
        $server = $this->createServerModel();
        $domain = ManagedDomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => 'Deletion test',
            'domain' => 'delete-' . strtolower(substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 12)) . '.example.com',
            'provider' => 'cloudflare',
            'zone_id' => str_repeat('a', 32),
            'api_token' => 'token',
            'enabled' => true,
            'label_pattern' => '^[a-z0-9-]+$',
        ]);
        $fqdn = 'survival.' . $domain->domain;
        $providerRecordId = 'provider-' . $domain->uuid;
        $attributes = [
            'server_id' => $server->id,
            'allocation_id' => $server->allocation_id,
            'managed_domain_id' => $domain->id,
            'dns_service_profile_id' => null,
            'label' => 'survival',
            'fqdn' => $fqdn,
            'routing_mode' => 'direct_dns',
            'detected_service' => 'Generic TCP service',
            'service_detection_source' => 'direct_dns_fallback',
            'status' => 'active',
            'desired_state_version' => 1,
            'public_target_type' => 'A',
            'public_target' => '203.0.113.10',
            'target_port' => 25565,
            'connection_address' => $fqdn . ':25565',
            'desired_record_plan' => ['records' => []],
        ];
        $managed = ManagedSubdomain::query()->create($attributes + ['uuid' => Uuid::uuid4()->toString()]);
        $record = ManagedDnsRecord::query()->create([
            'managed_subdomain_id' => $managed->id,
            'provider' => 'cloudflare',
            'zone_id' => $domain->zone_id,
            'provider_record_id' => $providerRecordId,
            'type' => 'A',
            'name' => $fqdn,
            'content' => '203.0.113.10',
            'ttl' => 300,
            'proxied' => false,
            'sync_status' => 'active',
        ]);

        $provider = $this->createMock(DnsProvider::class);
        $provider->expects($this->once())
            ->method('deleteRecord')
            ->with(
                $this->callback(fn (ManagedDomain $candidate) => $candidate->is($domain)),
                $providerRecordId,
            );
        $factory = $this->createMock(DnsProviderFactory::class);
        $factory->method('for')->willReturn($provider);
        $policies = $this->createMock(SubdomainPolicyResolver::class);

        (new ManagedDnsSynchronizer($factory, $policies))->delete($managed);

        $this->assertDatabaseMissing('managed_dns_records', ['id' => $record->id]);
        $this->assertDatabaseMissing('managed_subdomains', ['id' => $managed->id]);

        $replacement = ManagedSubdomain::query()->create($attributes + ['uuid' => Uuid::uuid4()->toString()]);
        $this->assertSame($fqdn, $replacement->fqdn);
    }
}
