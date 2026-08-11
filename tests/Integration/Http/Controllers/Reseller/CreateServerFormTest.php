<?php

namespace Pterodactyl\Tests\Integration\Http\Controllers\Reseller;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Reseller;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

/**
 * The reseller create-server form is stock js/admin/new-server.js driven by a
 * JavaScript::put payload the controller builds. That split means the form can
 * break in ways the page still renders fine through — these guard the contract
 * between the two.
 */
class CreateServerFormTest extends HttpTestCase
{
    protected $defaultHeaders = [];

    /**
     * new-server.js reads `nests[nestId].eggs[eggId].variables` to render the
     * Service Variables inputs. A plain with('eggs') omits that relation and the
     * section renders as a heading with nothing under it — the page is still a
     * 200, so only an assertion on the payload catches it.
     */
    public function testTheNestPayloadIncludesEggVariables(): void
    {
        [$owner] = $this->createResellerWithNode();

        $content = $this->actingAs($owner)->get('/reseller/servers/new')->assertOk()->getContent();

        $this->assertStringContainsString('appendVariablesTo', $content, 'The variables container should be present.');

        $payload = $this->extractNestPayload($content);
        $egg = $this->firstEgg($payload);

        $this->assertArrayHasKey('variables', $egg, 'Each egg must carry its variables for the form to render them.');
        $this->assertNotEmpty($egg['variables'], 'The seeded egg should have at least one variable.');
        $this->assertArrayHasKey('env_variable', $egg['variables'][0]);
    }

    /**
     * new-server.js also populates the Docker image dropdown and startup command
     * from the same payload.
     */
    public function testTheNestPayloadIncludesDockerImagesAndStartup(): void
    {
        [$owner] = $this->createResellerWithNode();

        $egg = $this->firstEgg(
            $this->extractNestPayload($this->actingAs($owner)->get('/reseller/servers/new')->getContent())
        );

        $this->assertArrayHasKey('docker_images', $egg);
        $this->assertArrayHasKey('startup', $egg);
    }

    /**
     * A checkbox with no value attribute submits the string "on", which fails the
     * `boolean` rule and blocks the whole form with "The start on completion
     * field must be true or false." The admin form gets away with omitting the
     * value only because it never validates this field.
     */
    public function testTheStartOnCompletionCheckboxSubmitsABooleanValue(): void
    {
        [$owner] = $this->createResellerWithNode();

        $content = $this->actingAs($owner)->get('/reseller/servers/new')->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="start_on_completion"[^>]*value="1"/',
            $content,
            'The checkbox must send value="1"; without it the browser sends "on" and validation rejects the form.'
        );
    }

    public function testStartOnCompletionAcceptsTheValueTheFormActuallySends(): void
    {
        [$owner, $reseller, $node] = $this->createResellerWithNode();
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id]);

        $this->actingAs($owner)
            ->post('/reseller/servers/new', $this->validPayload($tenant, $node, $allocation) + [
                'start_on_completion' => '1',
            ])
            ->assertSessionDoesntHaveErrors('start_on_completion');
    }

    /**
     * Pins the reason for the value="1" attribute: the raw browser default is
     * genuinely invalid, so if someone drops the attribute the form breaks again.
     */
    public function testStartOnCompletionRejectsTheRawCheckboxDefault(): void
    {
        [$owner, $reseller, $node] = $this->createResellerWithNode();
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id]);

        $this->actingAs($owner)
            ->post('/reseller/servers/new', $this->validPayload($tenant, $node, $allocation) + [
                'start_on_completion' => 'on',
            ])
            ->assertSessionHasErrors('start_on_completion');
    }

    /**
     * An unchecked box sends nothing at all, which must also be acceptable.
     */
    public function testStartOnCompletionMayBeOmittedEntirely(): void
    {
        [$owner, $reseller, $node] = $this->createResellerWithNode();
        $tenant = User::factory()->create(['reseller_id' => $reseller->id]);
        $allocation = Allocation::factory()->create(['node_id' => $node->id]);

        $this->actingAs($owner)
            ->post('/reseller/servers/new', $this->validPayload($tenant, $node, $allocation))
            ->assertSessionDoesntHaveErrors('start_on_completion');
    }

    private function validPayload(User $tenant, Node $node, Allocation $allocation): array
    {
        // CreatesTestModels::getBungeecordEgg() is private to the trait, so
        // resolve the seeded egg directly.
        $egg = Egg::query()->where('author', 'support@pterodactyl.io')->firstOrFail();

        return [
            'name' => 'ValidationProbe',
            'owner_id' => $tenant->id,
            'node_id' => $node->id,
            'allocation_id' => $allocation->id,
            'memory' => 128,
            'swap' => 0,
            'io' => 500,
            'cpu' => 0,
            'disk' => 512,
            'nest_id' => $egg->nest_id,
            'egg_id' => $egg->id,
            'startup' => 'java -jar server.jar',
            'image' => 'ghcr.io/pterodactyl/yolks:java_17',
            'database_limit' => 0,
            'allocation_limit' => 0,
            'backup_limit' => 0,
        ];
    }

    /**
     * @return array{0: User, 1: Reseller, 2: Node}
     */
    private function createResellerWithNode(): array
    {
        $owner = User::factory()->create();
        $reseller = Reseller::factory()->create(['user_id' => $owner->id]);
        $node = Node::factory()->create(['location_id' => Location::factory()->create()->id]);
        $reseller->nodes()->sync([$node->id]);

        return [$owner->refresh(), $reseller, $node];
    }

    /**
     * laracasts/utilities emits one assignment per variable
     * (`Pterodactyl.nests = {...};`) rather than a single object, and the JSON
     * contains braces of its own — so scan forward balancing brackets rather
     * than trying to regex a nested structure.
     */
    private function extractNestPayload(string $content): array
    {
        $marker = 'Pterodactyl.nests = ';
        $start = strpos($content, $marker);
        $this->assertNotFalse($start, 'Could not locate the JavaScript::put nests payload in the page.');

        $json = $this->balancedJsonAt($content, $start + strlen($marker));

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'The nests payload should be valid JSON.');

        return $decoded;
    }

    /**
     * Read one complete JSON object starting at $offset, respecting strings and
     * escapes so braces inside values don't end it early.
     */
    private function balancedJsonAt(string $content, int $offset): string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $offset; $i < strlen($content); ++$i) {
            $char = $content[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                ++$depth;
            } elseif ($char === '}') {
                if (--$depth === 0) {
                    return substr($content, $offset, $i - $offset + 1);
                }
            }
        }

        $this->fail('The nests payload was not a balanced JSON object.');
    }

    private function firstEgg(array $nests): array
    {
        foreach ($nests as $nest) {
            if (!empty($nest['eggs'])) {
                return reset($nest['eggs']);
            }
        }

        $this->fail('No nest in the payload carried any eggs.');
    }
}
