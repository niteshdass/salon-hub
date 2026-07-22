<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' ' . fake()->randomElement(['Downtown', 'Uptown', 'Central', 'Riverside', 'Main']),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => fake()->countryCode(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'opening_hours_json' => [
                'mon' => ['09:00', '18:00'],
                'tue' => ['09:00', '18:00'],
                'wed' => ['09:00', '18:00'],
                'thu' => ['09:00', '18:00'],
                'fri' => ['09:00', '18:00'],
                'sat' => ['10:00', '16:00'],
                'sun' => null,
            ],
        ];
    }
}
