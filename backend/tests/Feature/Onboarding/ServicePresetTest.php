<?php

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServicePresetTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $slug, string $role = 'owner'): string
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        return User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => $role,
            'status' => 'active',
        ])->createToken('api')->plainTextToken;
    }

    public function test_it_lists_every_salon_type_with_named_services(): void
    {
        $response = $this->withToken($this->token('alpha'))->getJson('/api/service-presets');

        $response->assertOk();
        $this->assertSame(
            ['hair', 'beauty', 'barber', 'spa', 'nails'],
            array_column($response->json('data'), 'key'),
        );

        foreach ($response->json('data') as $type) {
            $this->assertNotEmpty($type['label']);
            $this->assertNotEmpty($type['services']);
            foreach ($type['services'] as $service) {
                $this->assertNotEmpty($service['name']);
                $this->assertIsInt($service['duration']);
                $this->assertGreaterThan(0, $service['duration']);
                // Prices are the owner's to set; a preset must never carry one.
                $this->assertArrayNotHasKey('price', $service);
            }
        }
    }

    public function test_it_requires_a_signed_in_member(): void
    {
        $this->getJson('/api/service-presets')->assertUnauthorized();
    }
}
