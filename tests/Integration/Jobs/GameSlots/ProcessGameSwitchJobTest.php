<?php

namespace Pterodactyl\Tests\Integration\Jobs\GameSlots;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Pterodactyl\Models\GameSwitchOperation;
use Pterodactyl\Jobs\GameSlots\ProcessGameSwitchJob;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\GameSlots\GameSlotStorageService;

class ProcessGameSwitchJobTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // IntegrationTestCase does not clean these up on its own; remove the
        // models this test creates so shared-database runs stay isolated.
        GameSwitchOperation::query()->forceDelete();
        GameSlot::query()->forceDelete();
        Server::query()->forceDelete();

        parent::tearDown();
    }

    private function makeServerWithSlots(): array
    {
        $server = $this->createServerModel(['status' => Server::STATUS_SWITCHING_GAME]);

        $source = GameSlot::factory()->active()->create([
            'server_id' => $server->id,
            'egg_id' => $server->egg_id,
            'nest_id' => $server->nest_id,
            'name' => 'Source Game',
        ]);
        $destination = GameSlot::factory()->create([
            'server_id' => $server->id,
            'egg_id' => $server->egg_id,
            'nest_id' => $server->nest_id,
            'name' => 'Destination Game',
            'installation_status' => GameSlot::INSTALL_INSTALLED,
        ]);

        $operation = GameSwitchOperation::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'source_slot_id' => $source->id,
            'destination_slot_id' => $destination->id,
            'state' => GameSwitchOperation::STATE_PENDING,
            'current_stage' => GameSwitchOperation::STAGE_PENDING,
            'checkpoint' => GameSwitchOperation::CHECKPOINT_CREATED,
            'lock_marker' => $server->id,
            'previous_power_state' => 'offline',
            'restore_power' => false,
        ]);

        return [$server, $source, $destination, $operation];
    }

    private function mockDependencies(bool $stashThrows = false): array
    {
        $power = \Mockery::mock(DaemonPowerRepository::class);
        $power->shouldReceive('setServer')->andReturnSelf();
        $power->shouldReceive('send')->andReturn(new \GuzzleHttp\Psr7\Response());

        $daemon = \Mockery::mock(DaemonServerRepository::class);
        $daemon->shouldReceive('setServer')->andReturnSelf();
        $daemon->shouldReceive('getDetails')->andReturn(['state' => 'offline', 'utilization' => ['disk_bytes' => 0]]);
        $daemon->shouldReceive('sync')->andReturnNull();
        $daemon->shouldReceive('reinstall')->andReturnNull();

        $storage = \Mockery::mock(GameSlotStorageService::class);
        $storage->shouldReceive('restoreSlotFiles')->andReturnNull();
        if ($stashThrows) {
            $storage->shouldReceive('stashActiveFiles')->andThrow(new \RuntimeException('simulated stash failure'));
        } else {
            $storage->shouldReceive('stashActiveFiles')->andReturnNull();
        }

        return [$power, $daemon, $storage];
    }

    public function testSuccessfulSwitchFlipsActiveSlotAndClearsStatus(): void
    {
        [$server, $source, $destination, $operation] = $this->makeServerWithSlots();
        [$power, $daemon, $storage] = $this->mockDependencies();

        (new ProcessGameSwitchJob($operation->id))->handle($power, $daemon, $storage);

        $operation->refresh();
        $source->refresh();
        $destination->refresh();
        $server->refresh();

        $this->assertSame(GameSwitchOperation::STATE_COMPLETED, $operation->state);
        $this->assertNull($operation->lock_marker);
        $this->assertTrue($destination->is_active);
        $this->assertFalse($source->is_active);
        $this->assertNull($server->status);
        // The server configuration now points at the destination slot's egg/image.
        $this->assertSame($destination->docker_image, $server->image);
    }

    public function testFailureRollsBackToSourceSlot(): void
    {
        [$server, $source, $destination, $operation] = $this->makeServerWithSlots();
        // stashActiveFiles throws on the forward path (before commit), so the
        // job must roll back and leave the source slot active.
        [$power, $daemon, $storage] = $this->mockDependencies(stashThrows: true);

        (new ProcessGameSwitchJob($operation->id))->handle($power, $daemon, $storage);

        $operation->refresh();
        $source->refresh();
        $destination->refresh();
        $server->refresh();

        $this->assertSame(GameSwitchOperation::STATE_FAILED_ROLLED_BACK, $operation->state);
        $this->assertSame(GameSwitchOperation::ROLLBACK_SUCCEEDED, $operation->rollback_state);
        $this->assertTrue($source->is_active, 'The source slot must remain active after a rolled-back switch.');
        $this->assertFalse($destination->is_active);
        $this->assertNull($server->status);
        $this->assertNull($operation->lock_marker);
    }

    public function testAlreadyTerminalOperationIsSkipped(): void
    {
        [, , , $operation] = $this->makeServerWithSlots();
        $operation->forceFill(['state' => GameSwitchOperation::STATE_COMPLETED])->save();

        [$power, $daemon, $storage] = $this->mockDependencies();

        // Should return immediately without touching the mocks' switch logic.
        (new ProcessGameSwitchJob($operation->id))->handle($power, $daemon, $storage);

        $operation->refresh();
        $this->assertSame(GameSwitchOperation::STATE_COMPLETED, $operation->state);
    }

    public function testResumesFromCommittedCheckpointWithoutRollback(): void
    {
        [$server, $source, $destination, $operation] = $this->makeServerWithSlots();

        // Simulate a worker that crashed right after committing: the destination
        // is already active and the checkpoint records the commit.
        $source->markInactive();
        $destination->markActive();
        $operation->forceFill([
            'checkpoint' => GameSwitchOperation::CHECKPOINT_COMMITTED,
            'state' => GameSwitchOperation::STATE_RUNNING,
        ])->save();

        [$power, $daemon, $storage] = $this->mockDependencies();

        (new ProcessGameSwitchJob($operation->id))->handle($power, $daemon, $storage);

        $operation->refresh();
        $destination->refresh();
        $server->refresh();

        $this->assertSame(GameSwitchOperation::STATE_COMPLETED, $operation->state);
        $this->assertTrue($destination->is_active);
        $this->assertNull($server->status);
    }
}
