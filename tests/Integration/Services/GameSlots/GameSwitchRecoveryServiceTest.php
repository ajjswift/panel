<?php

namespace Pterodactyl\Tests\Integration\Services\GameSlots;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Pterodactyl\Models\GameSwitchOperation;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\GameSlots\GameSwitchRecoveryService;

class GameSwitchRecoveryServiceTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        GameSwitchOperation::query()->forceDelete();
        GameSlot::query()->forceDelete();
        Server::query()->forceDelete();

        parent::tearDown();
    }

    public function testHealClearsAStrandedSwitchingStatus(): void
    {
        [$server, $active] = $this->serverWithSlots(Server::STATUS_SWITCHING_GAME);
        // A terminal (failed) operation — nothing is actually running.
        $this->operation($server, $active, GameSwitchOperation::STATE_FAILED_ROLLED_BACK);

        $healed = $this->service()->healStaleState($server->refresh());

        $this->assertTrue($healed);
        $this->assertNull($server->refresh()->status);
        $this->assertSame(1, $server->gameSlots()->where('is_active', true)->count());
    }

    public function testHealLeavesALiveSwitchAlone(): void
    {
        [$server, $active] = $this->serverWithSlots(Server::STATUS_SWITCHING_GAME);
        $this->operation($server, $active, GameSwitchOperation::STATE_RUNNING);

        $healed = $this->service()->healStaleState($server->refresh());

        $this->assertFalse($healed);
        $this->assertSame(Server::STATUS_SWITCHING_GAME, $server->refresh()->status);
    }

    public function testHealGuaranteesASingleActiveSlot(): void
    {
        [$server] = $this->serverWithSlots(null);
        // Corrupt the state: no slot is active.
        $server->gameSlots()->update(['is_active' => false, 'active_marker' => null]);

        $this->service()->healStaleState($server->refresh());

        $this->assertSame(1, $server->gameSlots()->where('is_active', true)->count());
    }

    public function testResetForServerClearsTheLockAndStatus(): void
    {
        $daemon = \Mockery::mock(DaemonServerRepository::class);
        $daemon->shouldReceive('setServer')->andReturnSelf();
        $daemon->shouldReceive('sync')->andReturnNull();
        $this->app->instance(DaemonServerRepository::class, $daemon);

        [$server, $active] = $this->serverWithSlots(Server::STATUS_SWITCHING_GAME);
        $operation = $this->operation($server, $active, GameSwitchOperation::STATE_RUNNING, lock: true);

        $this->service()->resetForServer($server->refresh());

        $this->assertNull($server->refresh()->status);
        $operation->refresh();
        $this->assertSame(GameSwitchOperation::STATE_FAILED_REQUIRES_ACTION, $operation->state);
        $this->assertNull($operation->lock_marker);
        $this->assertSame(1, $server->gameSlots()->where('is_active', true)->count());
    }

    private function service(): GameSwitchRecoveryService
    {
        return $this->app->make(GameSwitchRecoveryService::class);
    }

    /**
     * @return array{Server, GameSlot}
     */
    private function serverWithSlots(?string $status): array
    {
        $server = $this->createServerModel(['status' => $status]);
        $active = GameSlot::factory()->active()->create([
            'server_id' => $server->id,
            'egg_id' => $server->egg_id,
            'nest_id' => $server->nest_id,
            'name' => 'Active Game',
        ]);
        GameSlot::factory()->create([
            'server_id' => $server->id,
            'egg_id' => $server->egg_id,
            'nest_id' => $server->nest_id,
            'name' => 'Other Game',
            'installation_status' => GameSlot::INSTALL_INSTALLED,
        ]);

        return [$server, $active];
    }

    private function operation(Server $server, GameSlot $active, string $state, bool $lock = false): GameSwitchOperation
    {
        return GameSwitchOperation::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'source_slot_id' => $active->id,
            'destination_slot_id' => $server->gameSlots()->where('id', '!=', $active->id)->value('id'),
            'state' => $state,
            'current_stage' => GameSwitchOperation::STAGE_PENDING,
            'checkpoint' => GameSwitchOperation::CHECKPOINT_CREATED,
            'lock_marker' => $lock ? $server->id : null,
            'previous_power_state' => 'offline',
            'restore_power' => false,
        ]);
    }
}
