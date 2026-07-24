<?php

namespace Pterodactyl\Tests\Unit\Services\Dns;

use PHPUnit\Framework\TestCase;
use Pterodactyl\Services\Dns\Results\DnsTarget;
use Pterodactyl\Services\Dns\HttpServiceDetector;
use Pterodactyl\Services\Dns\MinecraftServiceDetector;
use Pterodactyl\Services\Dns\AllocationServiceDetector;
use Pterodactyl\Services\Dns\Results\DetectedAllocationService;

class AllocationServiceDetectorTest extends TestCase
{
    public function testHttpWinsWithoutAttemptingMinecraft(): void
    {
        $target = new DnsTarget('A', '1.1.1.1', 'allocation_ip');
        $http = $this->createMock(HttpServiceDetector::class);
        $minecraft = $this->createMock(MinecraftServiceDetector::class);
        $http->expects($this->once())->method('detect')->with($target, 8123)->willReturn('http');
        $minecraft->expects($this->never())->method('detect');

        $result = (new AllocationServiceDetector($http, $minecraft))->detect($target, 8123);

        $this->assertSame(DetectedAllocationService::HTTP, $result->type);
    }

    public function testMinecraftRunsOnlyAfterHttpFails(): void
    {
        $target = new DnsTarget('A', '1.1.1.1', 'allocation_ip');
        $http = $this->createMock(HttpServiceDetector::class);
        $minecraft = $this->createMock(MinecraftServiceDetector::class);
        $http->expects($this->once())->method('detect')->with($target, 25565)->willReturn(null);
        $minecraft->expects($this->once())->method('detect')->with($target, 25565)->willReturn(true);

        $result = (new AllocationServiceDetector($http, $minecraft))->detect($target, 25565);

        $this->assertSame(DetectedAllocationService::MINECRAFT_JAVA, $result->type);
    }

    public function testUnknownIsReturnedOnlyAfterBothProtocolChecksFail(): void
    {
        $target = new DnsTarget('A', '1.1.1.1', 'allocation_ip');
        $http = $this->createMock(HttpServiceDetector::class);
        $minecraft = $this->createMock(MinecraftServiceDetector::class);
        $http->expects($this->once())->method('detect')->willReturn(null);
        $minecraft->expects($this->once())->method('detect')->willReturn(false);

        $result = (new AllocationServiceDetector($http, $minecraft))->detect($target, 27015);

        $this->assertSame(DetectedAllocationService::UNKNOWN, $result->type);
    }
}
