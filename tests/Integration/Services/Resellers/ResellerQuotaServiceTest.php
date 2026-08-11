<?php

namespace Pterodactyl\Tests\Integration\Services\Resellers;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Reseller;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Resellers\ResellerQuotaService;

class ResellerQuotaServiceTest extends IntegrationTestCase
{
    private ResellerQuotaService $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(ResellerQuotaService::class);
    }

    public function testUsageIsZeroForAFreshReseller(): void
    {
        $usage = $this->service->usage($this->createReseller(['memory' => 2048]));

        $this->assertSame(0, $usage->used('memory'));
        $this->assertSame(2048, $usage->limit('memory'));
        $this->assertSame(2048, $usage->remaining('memory'));
        $this->assertSame(0, $usage->percentage('memory'));
    }

    public function testUsageSumsOnlyThisResellersServers(): void
    {
        $reseller = $this->createReseller();
        $other = $this->createReseller();

        $this->createServerFor($reseller, ['memory' => 1024, 'disk' => 5120]);
        $this->createServerFor($reseller, ['memory' => 512, 'disk' => 2048]);
        $this->createServerFor($other, ['memory' => 8192, 'disk' => 99999]);

        $usage = $this->service->usage($reseller);

        $this->assertSame(1536, $usage->used('memory'));
        $this->assertSame(7168, $usage->used('disk'));
        $this->assertSame(2, $usage->used('server_limit'));
    }

    public function testAllocationIsRefusedOnceThePoolIsExhausted(): void
    {
        $reseller = $this->createReseller(['memory' => 2048]);
        $this->createServerFor($reseller, ['memory' => 1536]);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('memory allowance');

        $this->service->assertCanAllocateServer($reseller, ['memory' => 1024]);
    }

    public function testAllocationIsAllowedRightUpToTheLimit(): void
    {
        $reseller = $this->createReseller(['memory' => 2048]);
        $this->createServerFor($reseller, ['memory' => 1024]);

        $this->service->assertCanAllocateServer($reseller, ['memory' => 1024]);

        $this->assertTrue(true, 'Allocating exactly the remaining allowance should be permitted.');
    }

    public function testServerCountIsCappedIndependentlyOfResources(): void
    {
        $reseller = $this->createReseller(['memory' => -1, 'server_limit' => 1]);
        $this->createServerFor($reseller, ['memory' => 128]);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('servers allowance');

        $this->service->assertCanAllocateServer($reseller, ['memory' => 128]);
    }

    /**
     * Editing a server must refund what it already consumes, otherwise a
     * reseller sitting at its cap could never even shrink a server.
     */
    public function testEditingAServerDoesNotBillItsCurrentUsageTwice(): void
    {
        $reseller = $this->createReseller(['memory' => 2048]);
        $server = $this->createServerFor($reseller, ['memory' => 2048]);

        $this->service->usage($reseller, true);
        $this->service->assertCanAllocateServer($reseller, ['memory' => 1024], $server);

        $this->assertTrue(true, 'Shrinking a server at the cap should be permitted.');
    }

    public function testEditingStillRefusesAnIncreaseBeyondTheCap(): void
    {
        $reseller = $this->createReseller(['memory' => 2048]);
        $server = $this->createServerFor($reseller, ['memory' => 1024]);

        $this->expectException(DisplayException::class);

        $this->service->assertCanAllocateServer($reseller, ['memory' => 4096], $server);
    }

    public function testUnlimitedNeverRefuses(): void
    {
        $reseller = Reseller::factory()->unlimited()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $this->service->assertCanAllocateServer($reseller, ['memory' => 999999, 'disk' => 999999]);
        $this->service->assertCanCreateUser($reseller);

        $this->assertTrue($this->service->usage($reseller)->isUnlimited('memory'));
        $this->assertNull($this->service->usage($reseller)->remaining('memory'));
    }

    public function testZeroMeansNoneRatherThanUnlimited(): void
    {
        $reseller = $this->createReseller(['user_limit' => 0]);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('you have none remaining');

        $this->service->assertCanCreateUser($reseller);
    }

    public function testUserLimitCountsTenants(): void
    {
        $reseller = $this->createReseller(['user_limit' => 1]);
        User::factory()->create(['reseller_id' => $reseller->id]);

        $this->expectException(DisplayException::class);

        $this->service->assertCanCreateUser($reseller);
    }

    /**
     * A pool cut below current usage should read as "nothing left", not as a
     * negative number leaking into the dashboard.
     */
    public function testRemainingIsClampedAtZeroWhenThePoolIsReduced(): void
    {
        $reseller = $this->createReseller(['memory' => 4096]);
        $this->createServerFor($reseller, ['memory' => 4096]);

        $reseller->forceFill(['memory' => 1024])->save();

        $usage = $this->service->usage($reseller, true);

        $this->assertSame(0, $usage->remaining('memory'));
        $this->assertSame(100, $usage->percentage('memory'));
    }

    private function createReseller(array $attributes = []): Reseller
    {
        return Reseller::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
        ], $attributes));
    }

    private function createServerFor(Reseller $reseller, array $attributes = [])
    {
        $owner = User::factory()->create(['reseller_id' => $reseller->id]);

        return $this->createServerModel(array_merge(['owner_id' => $owner->id], $attributes));
    }
}
