<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use PHPUnit\Framework\TestCase;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\DnsServiceProfile;
use Pterodactyl\Services\Dns\DnsRecordPlanner;
use Pterodactyl\Services\Dns\Results\DnsTarget;

class DnsRecordPlannerTest extends TestCase
{
    public function testDetectedMinecraftCreatesOnlySrvForCustomPort(): void
    {
        $plan = (new DnsRecordPlanner())->build(
            'play.example.com',
            $this->allocation(25572),
            $this->domain(),
            $this->minecraftProfile(),
            new DnsTarget('A', '1.1.1.1', 'allocation_ip'),
            minecraftDetected: true,
            srvTarget: 'node.example.net',
        );

        $this->assertTrue($plan->portDiscoverable);
        $this->assertSame('play.example.com', $plan->connectionAddress);
        $this->assertTrue($plan->minecraftDetected);
        $this->assertCount(1, $plan->records);
        $this->assertSame([
            'type' => 'SRV',
            'name' => '_minecraft._tcp.play.example.com',
            'port' => 25572,
            'target' => 'node.example.net',
        ], collect($plan->records[0])->only(['type', 'name', 'port', 'target'])->all());
    }

    public function testDetectedMinecraftUsesSrvOnDefaultPortForConsistency(): void
    {
        $plan = (new DnsRecordPlanner())->build(
            'play.example.com',
            $this->allocation(25565),
            $this->domain(),
            $this->minecraftProfile(),
            new DnsTarget('AAAA', '2001:4860:4860::8888', 'node_ipv6'),
            minecraftDetected: true,
            srvTarget: 'node.example.net',
        );

        $this->assertCount(1, $plan->records);
        $this->assertSame('SRV', $plan->records[0]['type']);
        $this->assertSame(25565, $plan->records[0]['port']);
        $this->assertSame('node.example.net', $plan->records[0]['target']);
    }

    public function testHttpDetectionTakesPrecedenceOverMinecraftProfile(): void
    {
        $plan = (new DnsRecordPlanner())->build(
            'dynmap.example.com',
            $this->allocation(8123),
            $this->domain(),
            $this->minecraftProfile(),
            new DnsTarget('A', '1.1.1.1', 'allocation_ip'),
            new DnsTarget('A', '8.8.8.8', 'node_reverse_proxy_ipv4'),
            'http',
            false,
            'node.example.net',
        );

        $this->assertSame('proxy', $plan->accessMethod);
        $this->assertSame('dynmap.example.com', $plan->connectionAddress);
        $this->assertSame('http', $plan->proxyTargetScheme);
        $this->assertSame('http_probe', $plan->webDetectionSource);
        $this->assertFalse($plan->minecraftDetected);
        $this->assertCount(1, $plan->records);
        $this->assertSame('A', $plan->records[0]['type']);
        $this->assertSame('8.8.8.8', $plan->records[0]['content']);
        $this->assertStringContainsString('https://dynmap.example.com', $plan->friendlyNote);
    }

    public function testUnknownServiceUsesAddressRecordAsLastResort(): void
    {
        $plan = (new DnsRecordPlanner())->build(
            'game.example.com',
            $this->allocation(27015),
            $this->domain(),
            $this->minecraftProfile(),
            new DnsTarget('CNAME', 'games.example.net', 'domain_cname'),
            minecraftDetected: false,
        );

        $this->assertFalse($plan->portDiscoverable);
        $this->assertFalse($plan->minecraftDetected);
        $this->assertSame('with_port', $plan->accessMethod);
        $this->assertSame('game.example.com:27015', $plan->connectionAddress);
        $this->assertCount(1, $plan->records);
        $this->assertSame('CNAME', $plan->records[0]['type']);
        $this->assertSame('games.example.net', $plan->records[0]['content']);
        $this->assertStringContainsString('cannot redirect arbitrary ports', $plan->explanation);
    }

    public function testDetectedMinecraftWithoutResolvableSrvTargetFallsBackToAddressRecord(): void
    {
        $plan = (new DnsRecordPlanner())->build(
            'play.example.com',
            $this->allocation(25572),
            $this->domain(),
            $this->minecraftProfile(),
            new DnsTarget('A', '1.1.1.1', 'allocation_ip'),
            minecraftDetected: true,
            srvTarget: null,
        );

        $this->assertSame('with_port', $plan->accessMethod);
        $this->assertCount(1, $plan->records);
        $this->assertSame('A', $plan->records[0]['type']);
    }

    public function testKnownHttpDetectionRemainsStableWithoutAnyProbeInPlanner(): void
    {
        $plan = (new DnsRecordPlanner())->build(
            'dynmap.example.com',
            $this->allocation(8123),
            $this->domain(),
            $this->minecraftProfile(),
            new DnsTarget('A', '1.1.1.1', 'allocation_ip'),
            new DnsTarget('A', '8.8.8.8', 'node_reverse_proxy_ipv4'),
            'http',
            false,
        );

        $this->assertSame('proxy', $plan->accessMethod);
        $this->assertSame('http', $plan->proxyTargetScheme);
        $this->assertSame('http_probe', $plan->webDetectionSource);
        $this->assertCount(1, $plan->records);
    }

    private function allocation(int $port): Allocation
    {
        return (new Allocation())->forceFill(['port' => $port]);
    }

    private function domain(): ManagedDomain
    {
        return (new ManagedDomain())->forceFill(['ttl' => 300, 'supports_srv' => true]);
    }

    private function minecraftProfile(): DnsServiceProfile
    {
        return (new DnsServiceProfile())->forceFill([
            'name' => 'Minecraft Java',
            'protocol' => 'tcp',
            'default_port' => 25565,
            'supports_srv' => true,
            'srv_service' => '_minecraft',
            'srv_protocol' => '_tcp',
            'srv_priority' => 0,
            'srv_weight' => 5,
            'portless_on_default_port' => true,
            'supports_direct_dns' => true,
        ]);
    }
}
