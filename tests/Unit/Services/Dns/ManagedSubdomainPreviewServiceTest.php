<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;
use Illuminate\Support\Collection;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Dns\DnsRecordPlanner;
use Pterodactyl\Services\Dns\DnsTargetResolver;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\HttpServiceDetector;
use Pterodactyl\Services\Dns\SubdomainPolicyResolver;
use Pterodactyl\Services\Dns\DnsServiceProfileResolver;
use Pterodactyl\Services\Dns\Results\SubdomainPolicyResult;
use Pterodactyl\Services\Dns\ManagedSubdomainPreviewService;
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
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->expects($this->never())->method('detect');
        $profiles = $this->createMock(DnsServiceProfileResolver::class);
        $profiles->expects($this->never())->method('resolve');

        $service = $this->service($policyResolver, $profiles, $this->createMock(DnsTargetResolver::class), $availability, $detector);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('DNS already exists.');
        $service->handle($server, new User(), $domain, $allocation, 'dynmap');
    }

    public function testDetectedWebsiteUsesTheNodeReverseProxyPlan(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();

        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolve')->willReturn(new DnsTarget('A', '203.0.113.10', 'allocation_ip'));
        $targets->method('resolveForReverseProxy')->willReturn(new DnsTarget('A', '198.51.100.20', 'node_reverse_proxy_ipv4'));
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->expects($this->once())->method('detect')->willReturn('http');

        $service = $this->service(
            $this->policyResolver($policy),
            $this->profileResolver($this->genericProfile()),
            $targets,
            $this->createMock(ManagedHostnameAvailabilityService::class),
            $detector,
        );

        $preview = $service->handle($server, new User(), $domain, $allocation, 'dynmap');

        $this->assertSame('proxy', $preview['record_plan']['access_method']);
        $this->assertSame('http', $preview['record_plan']['proxy_target_scheme']);
        $this->assertSame('Website', $preview['detected_service']);
        $this->assertSame('198.51.100.20', $preview['public_target']['value']);
        $this->assertSame('198.51.100.20', $preview['record_plan']['records'][0]['content']);
    }

    public function testWebsiteWithoutReverseProxyFallsBackToAPortAddress(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();

        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolve')->willReturn(new DnsTarget('A', '203.0.113.10', 'allocation_ip'));
        $targets->method('resolveForReverseProxy')->willReturn(null);
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->method('detect')->willReturn('http');

        $service = $this->service(
            $this->policyResolver($policy),
            $this->profileResolver($this->genericProfile()),
            $targets,
            $this->createMock(ManagedHostnameAvailabilityService::class),
            $detector,
        );

        $preview = $service->handle($server, new User(), $domain, $allocation, 'dynmap');

        // No node reverse proxy: creation still succeeds with a working record,
        // the address just carries the port instead of being hidden by a proxy.
        $this->assertSame('with_port', $preview['record_plan']['access_method']);
        $this->assertSame('dynmap.example.com:8123', $preview['record_plan']['connection_address']);
        $this->assertSame('A', $preview['record_plan']['records'][0]['type']);
    }

    public function testNonWebGameUsesAnSrvRecordWhenSupported(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();

        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolve')->willReturn(new DnsTarget('A', '203.0.113.10', 'allocation_ip'));
        $targets->method('resolveForReverseProxy')->willReturn(null);
        $targets->method('resolveForSrv')->willReturn('node.example.net');
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->method('detect')->willReturn(null);

        $service = $this->service(
            $this->policyResolver($policy),
            $this->profileResolver($this->genericProfile()),
            $targets,
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

    public function testNonWebGameFallsBackToAPortAddressWithoutAnSrvTarget(): void
    {
        [$server, $allocation, $domain, $policy] = $this->context();

        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolve')->willReturn(new DnsTarget('A', '203.0.113.10', 'allocation_ip'));
        $targets->method('resolveForReverseProxy')->willReturn(null);
        $targets->method('resolveForSrv')->willReturn(null);
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->method('detect')->willReturn(null);

        $service = $this->service(
            $this->policyResolver($policy),
            $this->profileResolver($this->genericProfile()),
            $targets,
            $this->createMock(ManagedHostnameAvailabilityService::class),
            $detector,
        );

        $preview = $service->handle($server, new User(), $domain, $allocation, 'survival');

        $this->assertSame('with_port', $preview['record_plan']['access_method']);
        $this->assertCount(1, $preview['record_plan']['records']);
        $this->assertSame('A', $preview['record_plan']['records'][0]['type']);
    }

    private function service(
        SubdomainPolicyResolver $policyResolver,
        DnsServiceProfileResolver $profiles,
        DnsTargetResolver $targets,
        ManagedHostnameAvailabilityService $availability,
        HttpServiceDetector $detector,
    ): ManagedSubdomainPreviewService {
        return new ManagedSubdomainPreviewService(
            $policyResolver,
            $profiles,
            $targets,
            new DnsRecordPlanner(),
            $availability,
            $detector,
        );
    }

    private function policyResolver(SubdomainPolicyResult $policy): SubdomainPolicyResolver
    {
        $resolver = $this->createMock(SubdomainPolicyResolver::class);
        $resolver->method('resolve')->willReturn($policy);

        return $resolver;
    }

    private function profileResolver(DnsServiceProfile $profile): DnsServiceProfileResolver
    {
        $resolver = $this->createMock(DnsServiceProfileResolver::class);
        $resolver->method('resolve')->willReturn(['profile' => $profile, 'source' => 'test']);

        return $resolver;
    }

    private function genericProfile(): DnsServiceProfile
    {
        return (new DnsServiceProfile())->forceFill([
            'id' => 40,
            'name' => 'Generic TCP',
            'protocol' => 'tcp',
            'supports_srv' => true,
            'srv_priority' => 0,
            'srv_weight' => 5,
        ]);
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
