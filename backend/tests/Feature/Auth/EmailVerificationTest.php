<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Email verification. The owner is not blocked from using the app while
 * unverified — the SPA nags instead — but the flag has to be real.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, token: string}
     */
    private function register(string $email = 'owner@glamour.test'): array
    {
        $response = $this->postJson('/api/auth/register', [
            'salon_name' => 'Glamour Studio',
            'email' => $email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated();

        return [
            'user' => User::where('email', $email)->firstOrFail(),
            'token' => $response->json('token'),
        ];
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        );
    }

    public function test_registration_sends_a_verification_notification(): void
    {
        Notification::fake();

        ['user' => $user] = $this->register();

        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_verification_link_points_at_the_spa(): void
    {
        Notification::fake();

        ['user' => $user] = $this->register();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            // The SPA forwards these query parameters straight back to the
            // API route, so the signature still matches there.
            return str_starts_with($url, config('app.frontend_url').'/verify-email')
                && str_contains($url, 'id='.$user->id)
                && str_contains($url, 'hash='.sha1($user->email))
                && str_contains($url, 'signature=');
        });
    }

    public function test_the_me_endpoint_exposes_the_verification_state(): void
    {
        ['user' => $user, 'token' => $token] = $this->register();

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email_verified', false);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email_verified', true);
    }

    public function test_a_signed_link_verifies_the_address(): void
    {
        Event::fake();
        ['user' => $user] = $this->register();

        $response = $this->getJson($this->verificationUrl($user));

        $response->assertOk();
        $response->assertJsonPath('verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_a_tampered_hash_does_not_verify(): void
    {
        ['user' => $user] = $this->register();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('someone-else@evil.test')],
            absolute: false,
        );

        $this->getJson($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_unsigned_link_does_not_verify(): void
    {
        ['user' => $user] = $this->register();

        $this->getJson("/api/auth/verify-email/{$user->id}/".sha1($user->email))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_does_not_verify(): void
    {
        ['user' => $user] = $this->register();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->subMinute(),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        );

        $this->getJson($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verifying_twice_is_harmless(): void
    {
        ['user' => $user] = $this->register();
        $url = $this->verificationUrl($user);

        $this->getJson($url)->assertOk();
        $verifiedAt = $user->fresh()->email_verified_at;

        $this->getJson($url)->assertOk()->assertJsonPath('verified', true);
        $this->assertTrue($verifiedAt->equalTo($user->fresh()->email_verified_at));
    }

    public function test_an_authenticated_user_can_request_another_email(): void
    {
        ['user' => $user, 'token' => $token] = $this->register();
        Notification::fake();

        $this->withToken($token)->postJson('/api/auth/email/resend')->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resending_is_a_no_op_once_verified(): void
    {
        ['user' => $user, 'token' => $token] = $this->register();
        $user->forceFill(['email_verified_at' => now()])->save();
        Notification::fake();

        $this->withToken($token)->postJson('/api/auth/email/resend')
            ->assertOk()
            ->assertJsonPath('verified', true);

        Notification::assertNothingSent();
    }

    public function test_a_guest_cannot_request_another_email(): void
    {
        $this->register();

        $this->postJson('/api/auth/email/resend')->assertUnauthorized();
    }
}
