<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use Pterodactyl\Models\Node;
use PHPUnit\Framework\TestCase;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Dns\DnsTargetResolver;

class DnsTargetResolverTest extends TestCase
{
    public function testPublicAllocationAddressTakesPrecedence(): void
    {
        $target = (new DnsTargetResolver())->resolve(
            $this->allocation('1.1.1.1', new Node()),
            new ManagedDomain(),
        );

        $this->assertSame('A', $target->recordType);
        $this->assertSame('1.1.1.1', $target->value);
        $this->assertSame('allocation_ip', $target->source);
    }

    public function testPrivateAllocationUsesExplicitNodeTarget(): void
    {
        $node = (new Node())->forceFill(['dns_target_ipv6' => '2001:4860:4860::8888']);
        $target = (new DnsTargetResolver())->resolve(
            $this->allocation('0.0.0.0', $node),
            new ManagedDomain(),
        );

        $this->assertSame('AAAA', $target->recordType);
        $this->assertSame('node_ipv6', $target->source);
    }

    public function testUnsafeHostnameFallbackIsRejected(): void
    {
        $this->expectException(DisplayException::class);

        $node = (new Node())->forceFill(['dns_target_hostname' => 'localhost']);
        (new DnsTargetResolver())->resolve(
            $this->allocation('10.0.0.5', $node),
            new ManagedDomain(),
        );
    }

    public function testSafeHostnameValidationRejectsInternalAndAcceptsPublicFqdn(): void
    {
        $resolver = new DnsTargetResolver();

        $this->assertFalse($resolver->isSafeHostname('node.internal'));
        $this->assertFalse($resolver->isSafeHostname('127.0.0.1'));
        $this->assertTrue($resolver->isSafeHostname('games.example.net'));
    }

    public function testReverseProxyTargetUsesEnabledNodesPublicAddress(): void
    {
        $node = (new Node())->forceFill([
            'reverse_proxy_enabled' => true,
            'dns_target_ipv4' => '1.1.1.1',
        ]);

        $target = (new DnsTargetResolver())->resolveForReverseProxy($this->allocation('10.0.0.5', $node));

        $this->assertNotNull($target);
        $this->assertSame('A', $target->recordType);
        $this->assertSame('1.1.1.1', $target->value);
        $this->assertSame('node_reverse_proxy_ipv4', $target->source);
    }

    public function testReverseProxyTargetRejectsDisabledNode(): void
    {
        $node = (new Node())->forceFill([
            'reverse_proxy_enabled' => false,
            'dns_target_ipv4' => '1.1.1.1',
        ]);

        $this->assertNull(
            (new DnsTargetResolver())->resolveForReverseProxy($this->allocation('10.0.0.5', $node))
        );
    }

    public function testSrvTargetUsesExistingNodeHostnameWithoutCreatingAddressRecord(): void
    {
        $node = (new Node())->forceFill([
            'fqdn' => 'wings.example.net',
            'dns_target_hostname' => 'games.example.net',
        ]);

        $this->assertSame(
            'games.example.net',
            (new DnsTargetResolver())->resolveForSrv($this->allocation('10.0.0.5', $node)),
        );
    }

    public function testSrvTargetFallsBackToNodeFqdnAndRejectsIpOnlyNode(): void
    {
        $resolver = new DnsTargetResolver();
        $node = (new Node())->forceFill(['fqdn' => 'wings.example.net']);
        $this->assertSame('wings.example.net', $resolver->resolveForSrv($this->allocation('10.0.0.5', $node)));

        $node = (new Node())->forceFill(['fqdn' => '1.1.1.1']);
        $this->assertNull($resolver->resolveForSrv($this->allocation('10.0.0.5', $node)));
    }

    private function allocation(string $ip, Node $node): Allocation
    {
        return (new Allocation())->forceFill(['ip' => $ip])->setRelation('node', $node);
    }
}
