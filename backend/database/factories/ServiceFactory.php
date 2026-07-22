<?php

namespace Database\Factories;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(fake()->numberBetween(1, 3), true),
            'description' => fake()->sentence(10),
            'duration' => fake()->randomElement([15, 30, 45, 60, 90]),
            'price' => fake()->randomFloat(2, 10, 200),
            'status' => ServiceStatus::ACTIVE->value,
        ];
    }
}
