<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Collection;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Dns\DnsRecordPlanner;
use Pterodactyl\Services\Dns\DnsTargetResolver;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\SubdomainPolicyResolver;
use Pterodactyl\Services\Dns\AllocationServiceDetector;
use Pterodactyl\Services\Dns\DnsServiceProfileResolver;
use Pterodactyl\Services\Dns\Results\SubdomainPolicyResult;
use Pterodactyl\Services\Dns\ManagedSubdomainPreviewService;
use Pterodactyl\Services\Dns\Results\DetectedAllocationService;
use Pterodactyl\Services\Dns\ManagedHostnameAvailabilityService;

class ManagedSubdomainPreviewServiceTest extends TestCase
{
    public function testExistingDnsIsRejectedBeforeAnyPortProbe(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();

        $policyResolver = $this->createMock(SubdomainPolicyResolver::class);
        $policyResolver->method('resolve')->willReturn($policy);
        $availability = $this->createMock(ManagedHostnameAvailabilityService::class);
        $availability->expects($this->once())
            ->method('assertAvailable')
            ->with($domain, 'dynmap.example.com')
            ->willThrowException(new DisplayException('DNS already exists.'));
        $detector = $this->createMock(AllocationServiceDetector::class);
        $detector->expects($this->never())->method('detect');
        $profiles = $this->createMock(DnsServiceProfileResolver::class);
        $profiles->expects($this->never())->method('resolve');

        $service = new ManagedSubdomainPreviewService(
            $policyResolver,
            $profiles,
            $this->createMock(DnsTargetResolver::class),
            new DnsRecordPlanner(),
            $availability,
            $detector,
        );

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('DNS already exists.');
        $service->handle($server, new User(), $domain, $allocation, 'dynmap');
    }

    public function testDetectedWebsiteCannotSilentlyFallBackToDirectDns(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();
        $profile = (new DnsServiceProfile())->forceFill([
            'id' => 40,
            'name' => 'Generic TCP',
            'protocol' => 'tcp',
            'supports_srv' => true,
        ]);

        $policyResolver = $this->createMock(SubdomainPolicyResolver::class);
        $policyResolver->method('resolve')->willReturn($policy);
        $profiles = $this->createMock(DnsServiceProfileResolver::class);
        $profiles->method('resolve')->willReturn(['profile' => $profile, 'source' => 'test']);
        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolve')->willReturn(new DnsTarget('A', '203.0.113.10', 'allocation_ip'));
        $targets->method('resolveForReverseProxy')->willReturn(null);
        $detector = $this->createMock(AllocationServiceDetector::class);
        $detector->expects($this->once())
            ->method('detect')
            ->willReturn(new DetectedAllocationService(DetectedAllocationService::HTTP));
        $availability = $this->createMock(ManagedHostnameAvailabilityService::class);
        $availability->expects($this->once())->method('assertAvailable');

        $service = new ManagedSubdomainPreviewService(
            $policyResolver,
            $profiles,
            $targets,
            new DnsRecordPlanner(),
            $availability,
            $detector,
        );

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('website was detected');
        $service->handle($server, new User(), $domain, $allocation, 'dynmap');
    }

    public function testDetectedWebsiteUsesTheNodeReverseProxyPlan(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();
        $profile = (new DnsServiceProfile())->forceFill([
            'id' => 40,
            'name' => 'Generic TCP',
            'protocol' => 'tcp',
            'supports_srv' => true,
        ]);

        $policyResolver = $this->createMock(SubdomainPolicyResolver::class);
        $policyResolver->method('resolve')->willReturn($policy);
        $profiles = $this->createMock(DnsServiceProfileResolver::class);
        $profiles->method('resolve')->willReturn(['profile' => $profile, 'source' => 'test']);
        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolve')->willReturn(new DnsTarget('A', '203.0.113.10', 'allocation_ip'));
        $targets->method('resolveForReverseProxy')->willReturn(new DnsTarget('A', '198.51.100.20', 'node_reverse_proxy_ipv4'));
        $detector = $this->createMock(AllocationServiceDetector::class);
        $detector->method('detect')->willReturn(new DetectedAllocationService(DetectedAllocationService::HTTP));

        $service = new ManagedSubdomainPreviewService(
            $policyResolver,
            $profiles,
            $targets,
            new DnsRecordPlanner(),
            $this->createMock(ManagedHostnameAvailabilityService::class),
            $detector,
        );

        $preview = $service->handle($server, new User(), $domain, $allocation, 'dynmap');

        $this->assertSame('proxy', $preview['record_plan']['access_method']);
        $this->assertSame('http', $preview['record_plan']['proxy_target_scheme']);
        $this->assertSame('198.51.100.20', $preview['public_target']['value']);
        $this->assertSame('198.51.100.20', $preview['record_plan']['records'][0]['content']);
    }

    public function testDetectedMinecraftUsesOnlyAnSrvRecord(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();
        $profile = (new DnsServiceProfile())->forceFill([
            'id' => 40,
            'name' => 'Generic TCP',
            'protocol' => 'tcp',
            'supports_srv' => true,
            'srv_priority' => 0,
            'srv_weight' => 5,
        ]);

        $policyResolver = $this->createMock(SubdomainPolicyResolver::class);
        $policyResolver->method('resolve')->willReturn($policy);
        $profiles = $this->createMock(DnsServiceProfileResolver::class);
        $profiles->method('resolve')->willReturn(['profile' => $profile, 'source' => 'test']);
        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolve')->willReturn(new DnsTarget('A', '203.0.113.10', 'allocation_ip'));
        $targets->method('resolveForReverseProxy')->willReturn(new DnsTarget('A', '198.51.100.20', 'node_reverse_proxy_ipv4'));
        $targets->method('resolveForSrv')->willReturn('node.example.net');
        $detector = $this->createMock(AllocationServiceDetector::class);
        $detector->method('detect')->willReturn(new DetectedAllocationService(DetectedAllocationService::MINECRAFT_JAVA));

        $service = new ManagedSubdomainPreviewService(
            $policyResolver,
            $profiles,
            $targets,
            new DnsRecordPlanner(),
            $this->createMock(ManagedHostnameAvailabilityService::class),
            $detector,
        );

        $preview = $service->handle($server, new User(), $domain, $allocation, 'survival');

        $this->assertCount(1, $preview['record_plan']['records']);
        $this->assertSame('SRV', $preview['record_plan']['records'][0]['type']);
        $this->assertSame('_minecraft._tcp.survival.example.com', $preview['record_plan']['records'][0]['name']);
        $this->assertSame('node.example.net', $preview['record_plan']['records'][0]['target']);
        $this->assertTrue($preview['record_plan']['minecraft_detected']);
    }

    public function testDetectedMinecraftCannotSilentlyFallBackWithoutAnSrvTarget(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();
        $profile = (new DnsServiceProfile())->forceFill([
            'id' => 40,
            'name' => 'Generic TCP',
            'protocol' => 'tcp',
            'supports_srv' => true,
        ]);

        $policyResolver = $this->createMock(SubdomainPolicyResolver::class);
        $policyResolver->method('resolve')->willReturn($policy);
        $profiles = $this->createMock(DnsServiceProfileResolver::class);
        $profiles->method('resolve')->willReturn(['profile' => $profile, 'source' => 'test']);
        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolve')->willReturn(new DnsTarget('A', '203.0.113.10', 'allocation_ip'));
        $targets->method('resolveForSrv')->willReturn(null);
        $detector = $this->createMock(AllocationServiceDetector::class);
        $detector->method('detect')->willReturn(new DetectedAllocationService(DetectedAllocationService::MINECRAFT_JAVA));

        $service = new ManagedSubdomainPreviewService(
            $policyResolver,
            $profiles,
            $targets,
            new DnsRecordPlanner(),
            $this->createMock(ManagedHostnameAvailabilityService::class),
            $detector,
        );

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('SRV record cannot be created');
        $service->handle($server, new User(), $domain, $allocation, 'survival');
    }

    /**
     * @return array{Server, Allocation, ManagedDomain, SubdomainPolicyResult}
     */
    private function context(): array
    {
        $server = (new Server())->forceFill(['id' => 10]);
        $allocation = (new Allocation())->forceFill(['id' => 20, 'server_id' => 10, 'port' => 8123]);
        $domain = (new ManagedDomain())->forceFill([
            'id' => 30,
            'domain' => 'example.com',
            'label_pattern' => '^[a-z0-9-]+$',
            'reserved_labels' => [],
            'supports_srv' => true,
            'ttl' => 300,
        ]);
        $policy = new SubdomainPolicyResult(
            enabled: true,
            canView: true,
            canCreate: true,
            canUpdate: true,
            canDelete: true,
            canRepair: true,
            limit: 5,
            used: 0,
            policySource: 'test',
            serviceProfile: null,
            eligibleDomains: new Collection([$domain]),
            disabledReason: null,
            disabledReasonCode: null,
        );

        return [$server, $allocation, $domain, $policy];
    }
}
