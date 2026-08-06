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
            // ISO-8601 weekdays, 1 = Monday .. 7 = Sunday. This is the API
            // contract everywhere else: SlotGenerator compares against
            // Carbon's dayOfWeekIso, and UpdateStaffRequest validates
            // between:1,7. The 0-indexed set this used to emit meant
            // StaffView loaded a factory-seeded member into the edit form and
            // posting it back UNCHANGED returned 422 on data the app created.
            'working_days_json' => fake()->randomElements([1, 2, 3, 4, 5, 6, 7], fake()->numberBetween(4, 6)),
            'working_hours_json' => [
                'start' => '09:00',
                'end' => '18:00',
            ],
        ];
    }
}
