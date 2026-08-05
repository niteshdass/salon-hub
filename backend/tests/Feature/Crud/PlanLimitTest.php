<?php

namespace Tests\Feature\Crud;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    private function registerOwner(): array
    {
        $res = $this->postJson('/api/auth/register', [
            'salon_name' => 'Limit Salon',
            'name' => 'Owner',
            'email' => 'owner@limit.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertStatus(201);

        return [$res->json('token'), Organization::where('slug', 'limit-salon')->firstOrFail()];
    }

    public function test_free_plan_refuses_a_second_branch(): void
    {
        [$token] = $this->registerOwner();

        // Registration already created the one branch the Free plan allows.
        $this->withToken($token)
            ->postJson('/api/branches', ['name' => 'Second Location'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Your free plan allows only 1 branch.']);
    }

    public function test_free_plan_refuses_the_eleventh_staff_member(): void
    {
        [$token] = $this->registerOwner();

        for ($i = 1; $i <= 10; $i++) {
            $this->withToken($token)->postJson('/api/staff', [
                'name' => "Stylist {$i}",
                'email' => "stylist{$i}@limit.test",
                'password' => 'secret1234',
            ])->assertStatus(201);
        }

        $this->withToken($token)->postJson('/api/staff', [
            'name' => 'Stylist 11',
            'email' => 'stylist11@limit.test',
            'password' => 'secret1234',
        ])
            ->assertStatus(422)
            ->assertJson(['message' => 'Your free plan allows only 10 staff.']);
    }
}
