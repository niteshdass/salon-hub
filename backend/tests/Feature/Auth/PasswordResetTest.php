<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Self-service password reset. The API is stateless, so the emailed link
 * points at the SPA, which posts the token back to /auth/reset-password.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $email = 'owner@glamour.test'): User
    {
        $this->postJson('/api/auth/register', [
            'salon_name' => 'Glamour Studio',
            'email' => $email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    public function test_requesting_a_link_sends_a_reset_notification(): void
    {
        $user = $this->owner();
        Notification::fake();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_reset_link_points_at_the_spa(): void
    {
        $user = $this->owner();
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('app.frontend_url').'/reset-password')
                && str_contains($url, 'token='.$notification->token)
                && str_contains($url, 'email='.urlencode($user->email));
        });
    }

    public function test_an_unknown_email_is_accepted_without_revealing_anything(): void
    {
        $this->owner();
        Notification::fake();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nobody@nowhere.test',
        ]);

        // Same 200 as a real address — a different status would let anyone
        // enumerate which emails have accounts.
        $response->assertOk();
        Notification::assertNothingSent();
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $user = $this->owner();
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'brand-new-pass',
        ])->assertOk();
    }

    public function test_resetting_revokes_every_existing_api_token(): void
    {
        $user = $this->owner();
        $this->assertSame(1, $user->tokens()->count()); // issued at registration

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => Password::broker()->createToken($user),
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        // A forgotten password may mean a stolen session; drop them all.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = $this->owner();

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => 'not-the-real-token',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $user = $this->owner();
        $token = Password::broker()->createToken($user);

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $user = $this->owner();

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => Password::broker()->createToken($user),
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
