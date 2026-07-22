<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'theme_color' => fake()->hexColor(),
            'about' => fake()->paragraph(3),
            'facebook' => 'https://facebook.com/' . fake()->userName(),
            'instagram' => 'https://instagram.com/' . fake()->userName(),
            'website' => fake()->url(),
        ];
    }
}
