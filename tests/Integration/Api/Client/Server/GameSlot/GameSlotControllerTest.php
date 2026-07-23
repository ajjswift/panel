<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\GameSlot;

use Pterodactyl\Models\Egg;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Pterodactyl\Models\Permission;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Pterodactyl\Models\GameSwitchOperation;
use Pterodactyl\Jobs\GameSlots\ProcessGameSwitchJob;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class GameSlotControllerTest extends ClientApiIntegrationTestCase
{
    protected function tearDown(): void
    {
        // Eggs are shared seed data and are not wrapped in the per-test cleanup,
        // so reset the switching flag we toggle during these tests.
        Egg::query()->update(['game_switch_enabled' => false]);

        parent::tearDown();
    }

    private function enableSwitchingEgg(Server $server): Egg
    {
        $egg = $server->egg;
        $egg->forceFill(['game_switch_enabled' => true])->save();

        return $egg;
    }

    public function testListingAdoptsALegacyServerIntoAnActiveSlot(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_READ]);
        $server->forceFill(['game_slot_limit' => 3, 'status' => null])->save();

        $this->assertDatabaseCount('game_slots', 0);

        $response = $this->actingAs($user)->getJson($this->slotsLink($server));
        $response->assertOk();
        $response->assertJsonPath('meta.slot_count', 1);
        $response->assertJsonPath('meta.slot_limit', 3);
        $response->assertJsonPath('data.0.attributes.is_active', true);

        $this->assertDatabaseCount('game_slots', 1);
    }

    public function testSlotStorageReferenceIsNeverExposed(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_READ]);
        $server->forceFill(['status' => null])->save();

        $response = $this->actingAs($user)->getJson($this->slotsLink($server));
        $response->assertOk();

        $this->assertStringNotContainsString('storage_reference', $response->getContent());
    }

    public function testCreationIsRejectedForEggNotEnabledForSwitching(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_CREATE]);
        $server->forceFill(['game_slot_limit' => 3, 'status' => null])->save();
        // Explicitly ensure the egg is NOT enabled for switching.
        $server->egg->forceFill(['game_switch_enabled' => false])->save();

        $response = $this->actingAs($user)->postJson($this->slotsLink($server), [
            'name' => 'Second Game',
            'egg_id' => $server->egg_id,
        ]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertDatabaseMissing('game_slots', ['name' => 'Second Game']);
    }

    public function testSlotLimitIsEnforcedOnTheBackend(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_CREATE]);
        // Limit of 1 means only the adopted slot may exist.
        $server->forceFill(['game_slot_limit' => 1, 'status' => null])->save();
        $this->enableSwitchingEgg($server);

        $response = $this->actingAs($user)->postJson($this->slotsLink($server), [
            'name' => 'Overflow',
            'egg_id' => $server->egg_id,
        ]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertDatabaseMissing('game_slots', ['name' => 'Overflow']);
    }

    public function testSlotCanBeCreatedWithinLimit(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_CREATE]);
        $server->forceFill(['game_slot_limit' => 3, 'status' => null])->save();
        $this->enableSwitchingEgg($server);

        $response = $this->actingAs($user)->postJson($this->slotsLink($server), [
            'name' => 'Second Game',
            'egg_id' => $server->egg_id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('attributes.is_active', false);
        $response->assertJsonPath('attributes.installation_status', GameSlot::INSTALL_NOT_INSTALLED);

        $this->assertDatabaseHas('game_slots', ['server_id' => $server->id, 'name' => 'Second Game']);
    }

    public function testCreationRequiresPermission(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_READ]);
        $server->forceFill(['game_slot_limit' => 3, 'status' => null])->save();
        $this->enableSwitchingEgg($server);

        $this->actingAs($user)->postJson($this->slotsLink($server), [
            'name' => 'Nope',
            'egg_id' => $server->egg_id,
        ])->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function testActiveSlotCannotBeDeleted(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_DELETE]);
        $server->forceFill(['status' => null])->save();

        $active = GameSlot::factory()->active()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id, 'name' => 'Active One']);

        $this->actingAs($user)->deleteJson($this->slotsLink($server, $active->uuid), ['confirm' => 'Active One'])
            ->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertDatabaseHas('game_slots', ['id' => $active->id]);
    }

    public function testDeletionRequiresNameConfirmation(): void
    {
        Bus::fake();
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_DELETE]);
        $server->forceFill(['status' => null])->save();

        GameSlot::factory()->active()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);
        $inactive = GameSlot::factory()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id, 'name' => 'Delete Me']);

        // Wrong confirmation string.
        $this->actingAs($user)->deleteJson($this->slotsLink($server, $inactive->uuid), ['confirm' => 'wrong'])
            ->assertStatus(Response::HTTP_BAD_REQUEST);
        Bus::assertNotDispatched(\Pterodactyl\Jobs\GameSlots\PurgeGameSlotFilesJob::class);

        // Correct confirmation string.
        $this->actingAs($user)->deleteJson($this->slotsLink($server, $inactive->uuid), ['confirm' => 'Delete Me'])
            ->assertStatus(Response::HTTP_NO_CONTENT);
        Bus::assertDispatched(\Pterodactyl\Jobs\GameSlots\PurgeGameSlotFilesJob::class);
    }

    public function testCrossServerSlotAccessIsBlocked(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_READ]);
        $server->forceFill(['status' => null])->save();

        // A slot belonging to a completely different server.
        $otherServer = $this->createServerModel();
        $foreign = GameSlot::factory()->create(['server_id' => $otherServer->id, 'egg_id' => $otherServer->egg_id, 'nest_id' => $otherServer->nest_id]);

        $this->actingAs($user)->getJson($this->slotsLink($server, $foreign->uuid))
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    public function testActivationEnqueuesASwitchAndLocksTheServer(): void
    {
        Queue::fake();
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_SWITCH]);
        $server->forceFill(['game_slot_limit' => 3, 'status' => null])->save();
        $this->enableSwitchingEgg($server);

        GameSlot::factory()->active()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);
        $destination = GameSlot::factory()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);

        $this->mock(DaemonServerRepository::class, function ($mock) {
            $mock->shouldReceive('setServer')->andReturnSelf();
            $mock->shouldReceive('getDetails')->andReturn(['state' => 'offline', 'utilization' => ['disk_bytes' => 0]]);
        });

        $response = $this->actingAs($user)->postJson($this->slotsLink($server, $destination->uuid) . '/activate', [
            'restart_after' => true,
        ]);
        $response->assertOk();

        $server->refresh();
        $this->assertSame(Server::STATUS_SWITCHING_GAME, $server->status);
        $this->assertDatabaseHas('game_switch_operations', [
            'server_id' => $server->id,
            'destination_slot_id' => $destination->id,
            'lock_marker' => $server->id,
        ]);

        Queue::assertPushed(ProcessGameSwitchJob::class);
    }

    public function testConcurrentSwitchIsRejectedByTheLock(): void
    {
        Queue::fake();
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_GAMESLOT_SWITCH]);
        $server->forceFill(['game_slot_limit' => 3, 'status' => null])->save();
        $this->enableSwitchingEgg($server);

        GameSlot::factory()->active()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);
        $destination = GameSlot::factory()->create(['server_id' => $server->id, 'egg_id' => $server->egg_id, 'nest_id' => $server->nest_id]);

        // An operation already holds the lock for this server.
        GameSwitchOperation::query()->create([
            'uuid' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'server_id' => $server->id,
            'destination_slot_id' => $destination->id,
            'state' => GameSwitchOperation::STATE_RUNNING,
            'current_stage' => GameSwitchOperation::STAGE_STOPPING,
            'checkpoint' => GameSwitchOperation::CHECKPOINT_CREATED,
            'lock_marker' => $server->id,
        ]);
        $server->forceFill(['status' => Server::STATUS_SWITCHING_GAME])->save();

        $this->mock(DaemonServerRepository::class, function ($mock) {
            $mock->shouldReceive('setServer')->andReturnSelf();
            $mock->shouldReceive('getDetails')->andReturn(['state' => 'offline', 'utilization' => ['disk_bytes' => 0]]);
        });

        // The server is in the switching state, so a second activation is
        // rejected by the state guard before it can create a duplicate lock.
        $this->actingAs($user)->postJson($this->slotsLink($server, $destination->uuid) . '/activate', [])
            ->assertStatus(Response::HTTP_CONFLICT);

        $this->assertSame(1, GameSwitchOperation::query()->where('server_id', $server->id)->count());
    }

    private function slotsLink(Server $server, ?string $slot = null): string
    {
        $base = "/api/client/servers/{$server->uuid}/game-slots";

        return $slot ? "$base/$slot" : $base;
    }
}
