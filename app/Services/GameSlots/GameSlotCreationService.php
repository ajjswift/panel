<?php

namespace Pterodactyl\Services\GameSlots;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Servers\VariableValidatorService;

class GameSlotCreationService
{
    public function __construct(
        private ConnectionInterface $connection,
        private GameSlotAdoptionService $adoptionService,
        private VariableValidatorService $validatorService,
    ) {
    }

    /**
     * Create a new (inactive) game slot for a server. Installation is deferred
     * until the slot is first activated, so creation never touches the node.
     *
     * @param array{name: string, egg_id: int, docker_image?: string|null, environment?: array} $data
     *
     * @throws DisplayException
     * @throws \Throwable
     */
    public function handle(Server $server, array $data, ?User $user = null): GameSlot
    {
        $egg = Egg::query()->findOrFail($data['egg_id']);

        if (!$egg->game_switch_enabled) {
            throw new DisplayException('The selected game is not available for game switching on this panel.');
        }

        $image = $data['docker_image'] ?? null;
        $allowedImages = array_values($egg->docker_images ?? []);
        if (is_null($image)) {
            $image = $allowedImages[0] ?? null;
        }

        if (empty($image) || !in_array($image, $allowedImages, true)) {
            throw new DisplayException('The selected Docker image is not allowed for this game.');
        }

        $environment = $this->validatedEnvironment($egg, $data['environment'] ?? [], $user);

        return $this->connection->transaction(function () use ($server, $egg, $data, $image, $environment) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);

            // Guarantee the legacy adoption slot exists before counting.
            $this->adoptionService->handle($server);

            if ($server->gameSlots()->count() >= $server->game_slot_limit) {
                throw new DisplayException('This server has reached its game slot limit and cannot create additional slots.');
            }

            $slot = new GameSlot();
            $slot->forceFill([
                'uuid' => Uuid::uuid4()->toString(),
                'server_id' => $server->id,
                'nest_id' => $egg->nest_id,
                'egg_id' => $egg->id,
                'name' => $data['name'],
                'docker_image' => $image,
                'startup' => $egg->startup,
                'environment' => $environment,
                'storage_reference' => Uuid::uuid4()->toString(),
                'installation_status' => GameSlot::INSTALL_NOT_INSTALLED,
                'is_active' => false,
            ]);
            $slot->save();

            return $slot;
        });
    }

    /**
     * Validate user-supplied variable values against the egg's rules and merge
     * them over the egg defaults.
     */
    private function validatedEnvironment(Egg $egg, array $fields, ?User $user): array
    {
        $validator = $this->validatorService;
        if (!is_null($user)) {
            $validator = $validator->setUserLevel(User::USER_LEVEL_USER);
        }

        // Seed any variable the caller did not supply with the egg's default so
        // that required variables the user never touched still validate — mirrors
        // how a new server is provisioned.
        foreach ($egg->variables as $variable) {
            if (!array_key_exists($variable->env_variable, $fields) || $fields[$variable->env_variable] === null) {
                $fields[$variable->env_variable] = $variable->default_value;
            }
        }

        $results = $validator->handle($egg->id, $fields);

        $environment = [];
        foreach ($egg->variables as $variable) {
            $environment[$variable->env_variable] = $variable->default_value;
        }
        foreach ($results as $result) {
            $environment[$result->key] = $result->value ?? $environment[$result->key] ?? '';
        }

        return $environment;
    }
}
