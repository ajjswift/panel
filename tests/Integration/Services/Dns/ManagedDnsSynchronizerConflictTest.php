<?php

namespace Pterodactyl\Tests\Integration\Services\Dns;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Contracts\Dns\DnsProvider;
use Pterodactyl\Services\Dns\DnsProviderFactory;
use Pterodactyl\Services\Dns\ManagedDnsSynchronizer;
use Pterodactyl\Services\Dns\SubdomainPolicyResolver;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Dns\Results\SubdomainPolicyResult;
use Pterodactyl\Exceptions\Service\Dns\DnsProviderException;

class ManagedDnsSynchronizerConflictTest extends IntegrationTestCase
{
    public function testSrvCreationStillRejectsAnAddressRecordAddedAfterPreflight(): void
    {
        $server = $this->createServerModel();
        $domain = ManagedDomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => 'Conflict test',
            'domain' => 'conflict-' . strtolower(substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 12)) . '.example.com',
            'provider' => 'cloudflare',
            'zone_id' => str_repeat('a', 32),
            'api_token' => Crypt::encrypt('token'),
            'enabled' => true,
            'ttl' => 300,
            'supports_srv' => true,
            'label_pattern' => '^[a-z0-9-]+$',
        ]);
        $fqdn = 'survival.' . $domain->domain;
        $srvName = '_minecraft._tcp.' . $fqdn;
        $managed = ManagedSubdomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'allocation_id' => $server->allocation_id,
            'managed_domain_id' => $domain->id,
            'dns_service_profile_id' => null,
            'label' => 'survival',
            'fqdn' => $fqdn,
            'routing_mode' => 'direct_dns',
            'detected_service' => 'Minecraft Java',
            'service_detection_source' => 'minecraft_probe',
            'status' => 'pending',
            'desired_state_version' => 1,
            'public_target_type' => 'A',
            'public_target' => '203.0.113.10',
            'target_port' => 25572,
            'connection_address' => $fqdn,
            'desired_record_plan' => [
                'records' => [[
                    'key' => 'srv',
                    'type' => 'SRV',
                    'name' => $srvName,
                    'content' => null,
                    'ttl' => 300,
                    'proxied' => false,
                    'priority' => 0,
                    'weight' => 5,
                    'port' => 25572,
                    'target' => 'node.example.net',
                ]],
                'access_method' => 'clean',
            ],
        ]);

        $provider = $this->createMock(DnsProvider::class);
        $provider->expects($this->exactly(3))
            ->method('listRecords')
            ->willReturnCallback(fn (ManagedDomain $actualDomain, string $name) => match($name) {
                $fqdn => [['id' => 'foreign-address-record', 'type' => 'A']],
                default => [],
            });
        $provider->expects($this->never())->method('createRecord');
        $factory = $this->createMock(DnsProviderFactory::class);
        $factory->method('for')->willReturn($provider);
        $policy = new SubdomainPolicyResult(
            enabled: true,
            visible: true,
            canView: true,
            canCreate: true,
            canUpdate: true,
            canDelete: true,
            canRepair: true,
            limit: 5,
            used: 1,
            policySource: 'test',
            serviceProfile: null,
            eligibleDomains: new Collection([$domain]),
            disabledReason: null,
            disabledReasonCode: null,
        );
        $policies = $this->createMock(SubdomainPolicyResolver::class);
        $policies->method('resolve')->willReturn($policy);

        try {
            (new ManagedDnsSynchronizer($factory, $policies))->synchronize($managed, 1);
            $this->fail('The synchronizer should reject the provider conflict.');
        } catch (DnsProviderException $exception) {
            $this->assertSame('record_conflict', $exception->providerErrorCode);
        }

        $managed->refresh();
        $this->assertSame('failed', $managed->status->value);
        $this->assertSame('record_conflict', $managed->last_error_code);
    }
}
