<?php

namespace Database\Factories;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\GameSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameSlotFactory extends Factory
{
    protected $model = GameSlot::class;

    public function definition(): array
    {
        return [
            'uuid' => Uuid::uuid4()->toString(),
            'name' => $this->faker->words(2, true),
            'docker_image' => 'ghcr.io/pterodactyl/yolks:java_17',
            'startup' => 'java -jar server.jar',
            'environment' => [],
            'storage_reference' => Uuid::uuid4()->toString(),
            'installation_status' => GameSlot::INSTALL_INSTALLED,
            'state' => GameSlot::STATE_NORMAL,
            'is_active' => false,
        ];
    }

    public function active(): self
    {
        // active_marker must equal server_id to satisfy the one-active-slot
        // unique index; set it once the server_id is known.
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ])->afterCreating(function (GameSlot $slot) {
            $slot->forceFill(['active_marker' => $slot->server_id])->save();
        });
    }
}
