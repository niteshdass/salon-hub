<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwner(string $email): User
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Acme',
            'slug' => 'acme',
            'email' => $email,
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        return User::create([
            'organization_id' => $org->id,
            'name' => 'Owner',
            'email' => $email,
            'password' => Hash::make('secret1234'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $this->makeOwner('brute@x.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'brute@x.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // The sixth attempt is refused before the credentials are even checked,
        // so a correct password on attempt six must still be rejected.
        $this->postJson('/api/auth/login', [
            'email' => 'brute@x.test',
            'password' => 'secret1234',
        ])->assertStatus(429);
    }

    public function test_throttle_is_scoped_per_email_not_globally(): void
    {
        $this->makeOwner('victim@x.test');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'attacker@x.test',
                'password' => 'wrong-password',
            ]);
        }

        // A different account must not be locked out by someone else's failures.
        $this->postJson('/api/auth/login', [
            'email' => 'victim@x.test',
            'password' => 'secret1234',
        ])->assertOk();
    }

    public function test_successful_login_clears_the_attempt_counter(): void
    {
        $this->makeOwner('clears@x.test');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'clears@x.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'clears@x.test',
            'password' => 'secret1234',
        ])->assertOk();

        // Counter reset: three more failures must not trip the limiter.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'clears@x.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }
    }
}
