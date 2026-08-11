<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Reseller;

use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Reseller;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

/**
 * The security core of the reseller feature: one reseller must never be able to
 * see or touch another's users and servers, and must never be able to grant
 * itself more than it was given.
 */
class ResellerIsolationTest extends HttpTestCase
{
    protected $defaultHeaders = [];

    public function testAnOrdinaryUserCannotReachTheResellerArea(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/reseller')
            ->assertForbidden();
    }

    public function testAnAdministratorIsNotAutomaticallyAReseller(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/reseller')
            ->assertForbidden();
    }

    public function testADisabledResellerLosesAccess(): void
    {
        $owner = User::factory()->create();
        Reseller::factory()->disabled()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->get('/reseller')->assertForbidden();
    }

    public function testAResellerCanReachItsOwnArea(): void
    {
        [$owner] = $this->createReseller();

        $this->actingAs($owner)->get('/reseller')->assertOk();
    }

    public function testAResellerCannotReachTheAdminArea(): void
    {
        [$owner] = $this->createReseller();

        $this->actingAs($owner)->get('/admin')->assertForbidden();
        $this->actingAs($owner)->get('/admin/resellers')->assertForbidden();
    }

    /**
     * Every read route that takes an id must 404 — not 403 — on another
     * reseller's resource, so existence itself isn't probeable.
     */
    public function testForeignResourcesAreNotFoundOnEveryScopedGetRoute(): void
    {
        [$owner] = $this->createReseller();
        [, $otherReseller] = $this->createReseller();

        $foreignUser = User::factory()->create(['reseller_id' => $otherReseller->id]);
        $foreignServer = $this->createServerModel(['owner_id' => $foreignUser->id]);

        $routes = [
            "/reseller/users/view/$foreignUser->id",
            "/reseller/servers/view/$foreignServer->id",
        ];

        foreach ($routes as $route) {
            $this->actingAs($owner)->get($route)->assertNotFound("Expected $route to 404.");
        }
    }

    public function testForeignResourcesCannotBeMutated(): void
    {
        [$owner] = $this->createReseller();
        [, $otherReseller] = $this->createReseller();

        $foreignUser = User::factory()->create(['reseller_id' => $otherReseller->id]);
        $foreignServer = $this->createServerModel(['owner_id' => $foreignUser->id]);

        $this->actingAs($owner)
            ->patch("/reseller/users/view/$foreignUser->id", [
                'email' => 'hijacked@example.com',
                'username' => 'hijacked',
                'name_first' => 'H',
                'name_last' => 'H',
                'language' => 'en',
            ])
            ->assertNotFound();

        $this->actingAs($owner)->delete("/reseller/users/view/$foreignUser->id")->assertNotFound();
        $this->actingAs($owner)->delete("/reseller/servers/view/$foreignServer->id")->assertNotFound();
        $this->actingAs($owner)
            ->post("/reseller/servers/view/$foreignServer->id/suspension", ['action' => 'suspend'])
            ->assertNotFound();

        $original = $foreignUser->email;
        $this->assertSame($original, $foreignUser->refresh()->email, 'The foreign account must be untouched.');
        $this->assertNotSame('hijacked', $foreignUser->username);
        $this->assertFalse($foreignServer->refresh()->isSuspended());
    }

    public function testUserListOnlyShowsOwnTenants(): void
    {
        [$owner, $reseller] = $this->createReseller();
        [, $otherReseller] = $this->createReseller();

        $mine = User::factory()->create(['reseller_id' => $reseller->id, 'email' => 'mine@example.com']);
        $theirs = User::factory()->create(['reseller_id' => $otherReseller->id, 'email' => 'theirs@example.com']);

        $this->actingAs($owner)
            ->get('/reseller/users')
            ->assertOk()
            ->assertSee($mine->email)
            ->assertDontSee($theirs->email);
    }

    public function testServerListOnlyShowsOwnServers(): void
    {
        [$owner, $reseller] = $this->createReseller();
        [, $otherReseller] = $this->createReseller();

        $mine = $this->createServerModel([
            'owner_id' => User::factory()->create(['reseller_id' => $reseller->id])->id,
            'name' => 'MyOwnServer',
        ]);
        $theirs = $this->createServerModel([
            'owner_id' => User::factory()->create(['reseller_id' => $otherReseller->id])->id,
            'name' => 'SomeoneElsesServer',
        ]);

        $this->actingAs($owner)
            ->get('/reseller/servers')
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee($theirs->name);
    }

    public function testCreatedUsersAreAlwaysTiedToTheCreatingReseller(): void
    {
        [$owner, $reseller] = $this->createReseller();
        [, $otherReseller] = $this->createReseller();

        $this->actingAs($owner)
            ->post('/reseller/users/new', [
                'email' => 'newtenant@example.com',
                'username' => 'newtenant',
                'name_first' => 'New',
                'name_last' => 'Tenant',
                'language' => 'en',
                // Both of these must be ignored outright.
                'root_admin' => 1,
                'reseller_id' => $otherReseller->id,
            ])
            ->assertSessionHasNoErrors();

        /** @var User $created */
        $created = User::query()->where('username', 'newtenant')->firstOrFail();

        $this->assertFalse($created->root_admin, 'A reseller must never be able to create an administrator.');
        $this->assertSame($reseller->id, $created->reseller_id, 'A new account must belong to the creating reseller.');
    }

    public function testUpdatingAUserCannotEscalateItToAdministrator(): void
    {
        [$owner, $reseller] = $this->createReseller();
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($owner)
            ->patch("/reseller/users/view/$tenant->id", [
                'email' => $tenant->email,
                'username' => $tenant->username,
                'name_first' => 'Still',
                'name_last' => 'Normal',
                'language' => 'en',
                'root_admin' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($tenant->refresh()->root_admin);
        $this->assertSame('Still', $tenant->name_first, 'The legitimate part of the update should still apply.');
    }

    public function testUserCreationIsRefusedOnceTheUserQuotaIsFull(): void
    {
        [$owner, $reseller] = $this->createReseller(['user_limit' => 1]);
        User::factory()->create(['reseller_id' => $reseller->id]);

        $this->actingAs($owner)
            ->post('/reseller/users/new', [
                'email' => 'overquota@example.com',
                'username' => 'overquota',
                'name_first' => 'Over',
                'name_last' => 'Quota',
                'language' => 'en',
            ]);

        $this->assertNull(User::query()->where('username', 'overquota')->first());
    }

    public function testServerCreationIsRejectedForANodeTheResellerWasNotGranted(): void
    {
        [$owner, $reseller] = $this->createReseller();
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);

        // A node that exists but was never added to reseller_nodes.
        $forbiddenNode = Node::factory()->create([
            'location_id' => \Pterodactyl\Models\Location::factory()->create()->id,
        ]);
        $allocation = \Pterodactyl\Models\Allocation::factory()->create(['node_id' => $forbiddenNode->id]);

        $this->actingAs($owner)
            ->post('/reseller/servers/new', [
                'name' => 'ShouldNotExist',
                'owner_id' => $tenant->id,
                'node_id' => $forbiddenNode->id,
                'allocation_id' => $allocation->id,
                'memory' => 128,
                'swap' => 0,
                'io' => 500,
                'cpu' => 0,
                'disk' => 512,
                'nest_id' => 1,
                'egg_id' => 1,
                'startup' => 'java -jar server.jar',
                'image' => 'ghcr.io/pterodactyl/yolks:java_17',
                'database_limit' => 0,
                'allocation_limit' => 0,
                'backup_limit' => 0,
            ])
            ->assertSessionHasErrors('node_id');

        $this->assertNull(Server::query()->where('name', 'ShouldNotExist')->first());
    }

    public function testServerCannotBeAssignedToAUserOutsideTheReseller(): void
    {
        [$owner, $reseller] = $this->createReseller();
        [, $otherReseller] = $this->createReseller();

        $node = Node::factory()->create([
            'location_id' => \Pterodactyl\Models\Location::factory()->create()->id,
        ]);
        $reseller->nodes()->sync([$node->id]);
        $allocation = \Pterodactyl\Models\Allocation::factory()->create(['node_id' => $node->id]);
        $foreignUser = User::factory()->create(['reseller_id' => $otherReseller->id]);

        $this->actingAs($owner)
            ->post('/reseller/servers/new', [
                'name' => 'ForeignOwner',
                'owner_id' => $foreignUser->id,
                'node_id' => $node->id,
                'allocation_id' => $allocation->id,
                'memory' => 128,
                'swap' => 0,
                'io' => 500,
                'cpu' => 0,
                'disk' => 512,
                'nest_id' => 1,
                'egg_id' => 1,
                'startup' => 'java -jar server.jar',
                'image' => 'ghcr.io/pterodactyl/yolks:java_17',
                'database_limit' => 0,
                'allocation_limit' => 0,
                'backup_limit' => 0,
            ])
            ->assertSessionHasErrors('owner_id');
    }

    /**
     * @return array{0: User, 1: Reseller}
     */
    private function createReseller(array $attributes = []): array
    {
        $owner = User::factory()->create();
        $reseller = Reseller::factory()->create(array_merge(['user_id' => $owner->id], $attributes));

        return [$owner->refresh(), $reseller];
    }
}
