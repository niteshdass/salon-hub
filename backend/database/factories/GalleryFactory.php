<?php

namespace Database\Factories;

use App\Models\Gallery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gallery>
 */
class GalleryFactory extends Factory
{
    protected $model = Gallery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'image' => 'gallery/' . fake()->uuid() . '.jpg',
            'title' => fake()->optional()->words(3, true),
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }
}
