<?php

namespace Pterodactyl\Tests\Integration\Jobs\GameSlots;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\ManagedDomain;
use Pterodactyl\Models\ManagedSubdomain;
use Pterodactyl\Models\GameSwitchOperation;
use Pterodactyl\Jobs\GameSlots\ProcessGameSwitchJob;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Services\GameSlots\GameSlotStorageService;
use Pterodactyl\Services\Dns\ManagedSubdomainReconciliationService;

class ProcessGameSwitchJobTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // IntegrationTestCase does not clean these up on its own; remove the
        // models this test creates so shared-database runs stay isolated.
        GameSwitchOperation::query()->forceDelete();
        GameSlot::query()->forceDelete();
        ManagedSubdomain::query()->forceDelete();
        ManagedDomain::query()->forceDelete();
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

    private function attachManagedSubdomain(Server $server): ManagedSubdomain
    {
        $domain = ManagedDomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'name' => 'Switch ' . Str::random(4),
            'domain' => Str::lower(Str::random(8)) . '.example.com',
            'provider' => 'cloudflare',
            'zone_id' => str_repeat('a', 32),
            'api_token' => Crypt::encrypt('token'),
            'enabled' => true,
            'ttl' => 300,
            'label_pattern' => '^[a-z0-9-]+$',
        ]);

        return ManagedSubdomain::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'allocation_id' => $server->allocation_id,
            'managed_domain_id' => $domain->id,
            'dns_service_profile_id' => null,
            'label' => 'play',
            'fqdn' => 'play.' . $domain->domain,
            'routing_mode' => 'direct_dns',
            'detected_service' => 'Minecraft Java',
            'service_detection_source' => 'profile',
            'status' => 'active',
            'desired_state_version' => 1,
            'public_target_type' => 'A',
            'public_target' => '203.0.113.10',
            'target_port' => 25565,
            'connection_address' => 'play.' . $domain->domain,
            'desired_record_plan' => ['records' => [], 'access_method' => 'clean'],
        ]);
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

    public function testDnsReconcileFailureDoesNotFailACommittedSwitch(): void
    {
        // On the synchronous queue the post-switch DNS reconcile runs inline, so
        // a DNS provider failure used to bubble up and surface a completed switch
        // as failed. The reconcile is now best-effort and must never do that.
        config(['queue.default' => 'sync']);

        [$server, $source, $destination, $operation] = $this->makeServerWithSlots();
        $this->attachManagedSubdomain($server);

        $reconciler = \Mockery::mock(ManagedSubdomainReconciliationService::class);
        $reconciler->shouldReceive('reconcile')->andThrow(new \RuntimeException('simulated DNS provider outage'));
        $this->app->instance(ManagedSubdomainReconciliationService::class, $reconciler);

        [$power, $daemon, $storage] = $this->mockDependencies();

        (new ProcessGameSwitchJob($operation->id))->handle($power, $daemon, $storage);

        $operation->refresh();
        $destination->refresh();
        $server->refresh();

        $this->assertSame(GameSwitchOperation::STATE_COMPLETED, $operation->state);
        $this->assertTrue($destination->is_active);
        $this->assertNull($server->status);
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
