<?php

namespace Database\Factories;

use App\Models\StaffProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffProfile>
 */
class StaffProfileFactory extends Factory
{
    protected $model = StaffProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'designation' => fake()->randomElement(['Senior Stylist', 'Barber', 'Colorist', 'Nail Technician', 'Beautician', 'Massage Therapist']),
            'bio' => fake()->sentence(12),
            'profile_image' => null,
            'working_days_json' => fake()->randomElements([0, 1, 2, 3, 4, 5, 6], fake()->numberBetween(4, 6)),
            'working_hours_json' => [
                'start' => '09:00',
                'end' => '18:00',
            ],
        ];
    }
}
