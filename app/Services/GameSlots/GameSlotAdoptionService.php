<?php

namespace Pterodactyl\Services\GameSlots;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\GameSlot;
use Illuminate\Database\ConnectionInterface;

/**
 * Lazily adopts servers that predate the game-slot feature. The server's
 * current egg, image, startup configuration, and variables become slot #1,
 * which is immediately active and marked installed. No files are moved: the
 * active slot's data always lives at the volume root, exactly where a legacy
 * server's files already are, so adoption is a metadata-only operation.
 */
class GameSlotAdoptionService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * Ensure the server has an active slot representing its current game.
     */
    public function handle(Server $server): GameSlot
    {
        $existing = $server->activeGameSlot()->first();
        if ($existing) {
            return $existing;
        }

        return $this->connection->transaction(function () use ($server) {
            // Row-lock the server so two concurrent requests cannot both adopt.
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);

            $existing = $server->activeGameSlot()->first();
            if ($existing) {
                return $existing;
            }

            $environment = [];
            foreach ($server->variables as $variable) {
                $environment[$variable->env_variable] = $variable->server_value ?? $variable->default_value;
            }

            $slot = new GameSlot();
            $slot->forceFill([
                'uuid' => Uuid::uuid4()->toString(),
                'server_id' => $server->id,
                'nest_id' => $server->nest_id,
                'egg_id' => $server->egg_id,
                'name' => $server->egg->name,
                'docker_image' => $server->image,
                'startup' => $server->startup,
                'environment' => $environment,
                'storage_reference' => Uuid::uuid4()->toString(),
                'installation_status' => $server->isInstalled()
                    ? GameSlot::INSTALL_INSTALLED
                    : GameSlot::INSTALL_NOT_INSTALLED,
                'is_active' => true,
                'active_marker' => $server->id,
                'last_activated_at' => $server->created_at,
            ]);
            $slot->save();

            return $slot;
        });
    }
}
