<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Services\Dns\DnsTargetResolver;
use Pterodactyl\Services\Dns\HttpServiceDetector;
use Pterodactyl\Services\Dns\MinecraftServiceDetector;
use Pterodactyl\Services\Dns\AllocationServiceDetector;
use Pterodactyl\Services\Dns\Results\DetectedAllocationService;

class AllocationServiceDetectorTest extends TestCase
{
    public function testHttpWinsWithoutAttemptingMinecraft(): void
    {
        $http = $this->createMock(HttpServiceDetector::class);
        $minecraft = $this->createMock(MinecraftServiceDetector::class);
        $http->expects($this->once())->method('detect')->with(['1.1.1.1'], 8123)->willReturn('http');
        $minecraft->expects($this->never())->method('detect');

        $result = $this->detector($http, $minecraft)->detect($this->allocation(8123));

        $this->assertSame(DetectedAllocationService::HTTP, $result->type);
    }

    public function testMinecraftRunsOnlyAfterHttpFails(): void
    {
        $http = $this->createMock(HttpServiceDetector::class);
        $minecraft = $this->createMock(MinecraftServiceDetector::class);
        $http->expects($this->once())->method('detect')->with(['1.1.1.1'], 25565)->willReturn(null);
        $minecraft->expects($this->once())->method('detect')->with(['1.1.1.1'], 25565)->willReturn(true);

        $result = $this->detector($http, $minecraft)->detect($this->allocation(25565));

        $this->assertSame(DetectedAllocationService::MINECRAFT_JAVA, $result->type);
    }

    public function testUnknownIsReturnedOnlyAfterBothProtocolChecksFail(): void
    {
        $http = $this->createMock(HttpServiceDetector::class);
        $minecraft = $this->createMock(MinecraftServiceDetector::class);
        $http->expects($this->once())->method('detect')->willReturn(null);
        $minecraft->expects($this->once())->method('detect')->willReturn(false);

        $result = $this->detector($http, $minecraft)->detect($this->allocation(27015));

        $this->assertSame(DetectedAllocationService::UNKNOWN, $result->type);
    }

    public function testUnknownWhenNoReachableProbeCandidates(): void
    {
        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolveProbeCandidates')->willReturn([]);
        $http = $this->createMock(HttpServiceDetector::class);
        $http->expects($this->never())->method('detect');

        $detector = new AllocationServiceDetector($targets, $http, $this->createMock(MinecraftServiceDetector::class));
        $result = $detector->detect($this->allocation(25565));

        $this->assertSame(DetectedAllocationService::UNKNOWN, $result->type);
    }

    private function detector(HttpServiceDetector $http, MinecraftServiceDetector $minecraft): AllocationServiceDetector
    {
        $targets = $this->createMock(DnsTargetResolver::class);
        $targets->method('resolveProbeCandidates')->willReturn(['1.1.1.1']);

        return new AllocationServiceDetector($targets, $http, $minecraft);
    }

    private function allocation(int $port): Allocation
    {
        return (new Allocation())->forceFill(['ip' => '1.1.1.1', 'port' => $port]);
    }
}
