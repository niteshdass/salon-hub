<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hour = fake()->numberBetween(8, 18);
        $minute = fake()->randomElement([0, 15, 30, 45]);
        $start = sprintf('%02d:%02d:00', $hour, $minute);
        $durationMinutes = fake()->randomElement([30, 45, 60, 90]);
        $end = date('H:i:s', strtotime($start) + ($durationMinutes * 60));

        return [
            'booking_date' => fake()->dateTimeBetween('-15 days', '+30 days')->format('Y-m-d'),
            'start_time' => $start,
            'end_time' => $end,
            'price' => fake()->randomElement([20, 25, 40, 60, 90]),
            'status' => fake()->randomElement(AppointmentStatus::cases())->value,
            'notes' => fake()->optional()->sentence(8),
        ];
    }
}
