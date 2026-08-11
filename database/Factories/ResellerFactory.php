<?php

namespace Database\Factories;

use Ramsey\Uuid\Uuid;
use Pterodactyl\Models\Reseller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Pterodactyl\Models\Reseller>
 */
class ResellerFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'uuid' => Uuid::uuid4()->toString(),
            'name' => $this->faker->company,
            'enabled' => true,
            'memory' => 16384,
            'disk' => 102400,
            'cpu' => 800,
            'server_limit' => 10,
            'user_limit' => 25,
            'database_limit' => 10,
            'allocation_limit' => 20,
            'backup_limit' => 20,
        ];
    }

    /**
     * A reseller with no cap on any dimension.
     */
    public function unlimited(): static
    {
        return $this->state(array_fill_keys(Reseller::QUOTA_DIMENSIONS, Reseller::UNLIMITED));
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
