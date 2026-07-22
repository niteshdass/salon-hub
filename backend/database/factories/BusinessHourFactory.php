<?php

namespace Database\Factories;

use App\Models\BusinessHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHour>
 */
class BusinessHourFactory extends Factory
{
    protected $model = BusinessHour::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekday' => fake()->numberBetween(0, 6),
            'open_time' => '09:00:00',
            'close_time' => '18:00:00',
            'is_closed' => false,
        ];
    }
}
