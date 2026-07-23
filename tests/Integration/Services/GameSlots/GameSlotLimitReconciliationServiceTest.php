<?php

namespace Pterodactyl\Tests\Integration\Services\GameSlots;

use Pterodactyl\Models\GameSlot;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\GameSlots\GameSlotLimitReconciliationService;

class GameSlotLimitReconciliationServiceTest extends IntegrationTestCase
{
    private GameSlotLimitReconciliationService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(GameSlotLimitReconciliationService::class);
    }

    protected function tearDown(): void
    {
        GameSlot::query()->forceDelete();
        \Pterodactyl\Models\Server::query()->forceDelete();

        parent::tearDown();
    }

    public function testExcessInactiveSlotsAreFlaggedWithoutDeletingData(): void
    {
        $server = $this->createServerModel(['game_slot_limit' => 1]);

        $active = GameSlot::factory()->active()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);
        $inactiveA = GameSlot::factory()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);
        $inactiveB = GameSlot::factory()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);

        $this->service->handle($server->refresh());

        // No rows deleted — data is preserved.
        $this->assertSame(3, $server->gameSlots()->count());

        // The active slot stays usable; the two inactive slots are over-limit.
        $this->assertSame(GameSlot::STATE_NORMAL, $active->refresh()->state);
        $this->assertSame(GameSlot::STATE_OVER_LIMIT, $inactiveA->refresh()->state);
        $this->assertSame(GameSlot::STATE_OVER_LIMIT, $inactiveB->refresh()->state);
    }

    public function testRaisingLimitRestoresOverLimitSlots(): void
    {
        $server = $this->createServerModel(['game_slot_limit' => 1]);

        GameSlot::factory()->active()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);
        $inactive = GameSlot::factory()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id, 'state' => GameSlot::STATE_OVER_LIMIT]);

        // Raise the allowance so the previously over-limit slot fits again.
        $server->forceFill(['game_slot_limit' => 5])->save();
        $this->service->handle($server->refresh());

        $this->assertSame(GameSlot::STATE_NORMAL, $inactive->refresh()->state);
    }

    public function testActiveSlotIsNeverMarkedOverLimit(): void
    {
        $server = $this->createServerModel(['game_slot_limit' => 1]);

        // An unusual state: the active slot was somehow left over_limit.
        $active = GameSlot::factory()->active()->create([
            'server_id' => $server->id,
            'egg_id' => $server->egg_id,
            'nest_id' => $server->nest_id,
            'state' => GameSlot::STATE_OVER_LIMIT,
        ]);

        $this->service->handle($server->refresh());

        $this->assertSame(GameSlot::STATE_NORMAL, $active->refresh()->state);
    }
}
