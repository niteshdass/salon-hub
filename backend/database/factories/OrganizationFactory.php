<?php

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionPlan;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'uuid' => fake()->unique()->uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'country' => fake()->countryCode(),
            'timezone' => fake()->timezone(),
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'AUD']),
            'logo' => null,
            'cover_image' => null,
            'subscription_plan' => fake()->randomElement(SubscriptionPlan::cases())->value,
            'status' => OrganizationStatus::ACTIVE->value,
        ];
    }
}
