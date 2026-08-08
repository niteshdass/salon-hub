<?php

namespace Database\Factories;

use App\Models\AppointmentService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentService>
 */
class AppointmentServiceFactory extends Factory
{
    protected $model = AppointmentService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Haircut', 'Blow Dry', 'Colour', 'Shave']),
            'price' => fake()->randomElement([15, 20, 40, 60]),
            'duration' => fake()->randomElement([15, 30, 45, 60]),
            'sort_order' => 0,
        ];
    }
}
