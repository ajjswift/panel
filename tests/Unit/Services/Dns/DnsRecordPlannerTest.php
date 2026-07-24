<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use PHPUnit\Framework\TestCase;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Services\Dns\DnsRecordPlanner;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\HttpServiceDetector;

class DnsRecordPlannerTest extends TestCase
{
    public function testMinecraftJavaCreatesSrvAndHidesNonDefaultPort(): void
    {
        $plan = $this->planner()->build(
            'play.example.com',
            $this->allocation(25572),
            $this->domain(),
            $this->profile([
                'name' => 'Minecraft Java',
                'default_port' => 25565,
                'supports_srv' => true,
                'srv_service' => '_minecraft',
                'srv_protocol' => '_tcp',
                'portless_on_default_port' => true,
            ]),
            new DnsTarget('A', '203.0.113.20', 'allocation_ip'),
        );

        $this->assertTrue($plan->portDiscoverable);
        $this->assertSame('play.example.com', $plan->connectionAddress);
        $this->assertCount(2, $plan->records);
        $this->assertFalse($plan->records[0]['proxied']);
        $this->assertFalse($plan->records[1]['proxied']);
        $this->assertSame([
            'type' => 'SRV',
            'name' => '_minecraft._tcp.play.example.com',
            'port' => 25572,
            'target' => 'play.example.com',
        ], collect($plan->records[1])->only(['type', 'name', 'port', 'target'])->all());
    }

    public function testMinecraftJavaAlwaysCreatesSrvOnDefaultPortForConsistency(): void
    {
        $plan = $this->planner()->build(
            'play.example.com',
            $this->allocation(25565),
            $this->domain(),
            $this->profile([
                'name' => 'Minecraft Java',
                'default_port' => 25565,
                'supports_srv' => true,
                'srv_service' => '_minecraft',
                'srv_protocol' => '_tcp',
                'portless_on_default_port' => true,
            ]),
            new DnsTarget('AAAA', '2001:4860:4860::8888', 'node_ipv6'),
        );

        $this->assertSame('play.example.com', $plan->connectionAddress);
        $this->assertSame('AAAA', $plan->records[0]['type']);
        $this->assertSame('play.example.com', $plan->records[1]['target']);
        $this->assertSame(25565, $plan->records[1]['port']);
    }

    public function testApprovedCnameTargetIsPreservedInAddressPlan(): void
    {
        $plan = $this->planner()->build(
            'game.example.com',
            $this->allocation(27015),
            $this->domain(),
            $this->profile([
                'name' => 'Generic TCP service',
                'supports_srv' => false,
                'portless_on_default_port' => false,
            ]),
            new DnsTarget('CNAME', 'games.example.net', 'domain_cname'),
        );

        $this->assertSame('CNAME', $plan->records[0]['type']);
        $this->assertSame('games.example.net', $plan->records[0]['content']);
        $this->assertSame('game.example.com:27015', $plan->connectionAddress);
    }

    public function testGenericDnsHonestlyIncludesThePort(): void
    {
        $plan = $this->planner()->build(
            'game.example.com',
            $this->allocation(27015),
            $this->domain(),
            $this->profile([
                'name' => 'Generic TCP service',
                'supports_srv' => false,
                'portless_on_default_port' => false,
            ]),
            new DnsTarget('A', '203.0.113.20', 'allocation_ip'),
        );

        $this->assertFalse($plan->portDiscoverable);
        $this->assertSame('game.example.com:27015', $plan->connectionAddress);
        $this->assertCount(1, $plan->records);
        $this->assertStringContainsString('cannot redirect arbitrary ports', $plan->explanation);
    }

    public function testDetectedWebsiteOverridesInheritedMinecraftProfileAndUsesNodeProxy(): void
    {
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->expects($this->once())
            ->method('detect')
            ->with(
                $this->callback(fn (DnsTarget $target) => $target->value === '1.1.1.1'),
                8123,
                'dynmap.example.com',
            )
            ->willReturn('http');

        $plan = (new DnsRecordPlanner($detector))->build(
            'dynmap.example.com',
            $this->allocation(8123),
            $this->domain(),
            $this->profile([
                'name' => 'Minecraft Java',
                'protocol' => 'tcp',
                'default_port' => 25565,
                'supports_srv' => true,
                'srv_service' => '_minecraft',
                'srv_protocol' => '_tcp',
                'portless_on_default_port' => true,
            ]),
            new DnsTarget('A', '1.1.1.1', 'allocation_ip'),
            new DnsTarget('A', '8.8.8.8', 'node_reverse_proxy_ipv4'),
        );

        $this->assertSame('proxy', $plan->accessMethod);
        $this->assertSame('dynmap.example.com', $plan->connectionAddress);
        $this->assertSame('http', $plan->proxyTargetScheme);
        $this->assertSame('http_probe', $plan->webDetectionSource);
        $this->assertCount(1, $plan->records);
        $this->assertSame('8.8.8.8', $plan->records[0]['content']);
        $this->assertStringContainsString('https://dynmap.example.com', $plan->friendlyNote);
    }

    public function testNonHttpAllocationKeepsInheritedGameSrvRecord(): void
    {
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->expects($this->once())->method('detect')->willReturn(null);

        $plan = (new DnsRecordPlanner($detector))->build(
            'play.example.com',
            $this->allocation(25572),
            $this->domain(),
            $this->profile([
                'name' => 'Minecraft Java',
                'protocol' => 'tcp',
                'default_port' => 25565,
                'supports_srv' => true,
                'srv_service' => '_minecraft',
                'srv_protocol' => '_tcp',
                'portless_on_default_port' => true,
            ]),
            new DnsTarget('A', '1.1.1.1', 'allocation_ip'),
            new DnsTarget('A', '8.8.8.8', 'node_reverse_proxy_ipv4'),
        );

        $this->assertSame('clean', $plan->accessMethod);
        $this->assertCount(2, $plan->records);
        $this->assertSame('SRV', $plan->records[1]['type']);
    }

    public function testKnownWebsiteDoesNotNeedToBeReprobedDuringReconciliation(): void
    {
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->expects($this->never())->method('detect');

        $plan = (new DnsRecordPlanner($detector))->build(
            'dynmap.example.com',
            $this->allocation(8123),
            $this->domain(),
            $this->profile([
                'name' => 'Minecraft Java',
                'protocol' => 'tcp',
                'supports_srv' => true,
                'srv_service' => '_minecraft',
                'srv_protocol' => '_tcp',
            ]),
            new DnsTarget('A', '1.1.1.1', 'allocation_ip'),
            new DnsTarget('A', '8.8.8.8', 'node_reverse_proxy_ipv4'),
            'http',
        );

        $this->assertSame('proxy', $plan->accessMethod);
        $this->assertSame('http', $plan->proxyTargetScheme);
        $this->assertSame('http_probe', $plan->webDetectionSource);
        $this->assertCount(1, $plan->records);
    }

    private function planner(): DnsRecordPlanner
    {
        $detector = $this->createMock(HttpServiceDetector::class);
        $detector->expects($this->never())->method('detect');

        return new DnsRecordPlanner($detector);
    }

    private function allocation(int $port): Allocation
    {
        return (new Allocation())->forceFill(['port' => $port]);
    }

    private function domain(): ManagedDomain
    {
        return (new ManagedDomain())->forceFill(['ttl' => 300, 'supports_srv' => true]);
    }

    private function profile(array $attributes): DnsServiceProfile
    {
        return (new DnsServiceProfile())->forceFill($attributes + [
            'protocol' => 'tcp',
            'srv_priority' => 0,
            'srv_weight' => 5,
            'supports_direct_dns' => true,
        ]);
    }
}
