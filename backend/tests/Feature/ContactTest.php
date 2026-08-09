<?php

namespace Tests\Feature;

use App\Mail\ContactMessageMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_submission_sends_mail_to_configured_address(): void
    {
        Mail::fake();
        config(['mail.contact_address' => 'inbox@glowhub.test']);

        $response = $this->postJson('/api/contact', [
            'name' => 'Ada Salon',
            'email' => 'ada@example.com',
            'message' => 'Interested in the Business plan for 3 branches.',
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        Mail::assertSent(ContactMessageMail::class, function (ContactMessageMail $mail) {
            return $mail->hasTo('inbox@glowhub.test')
                && $mail->name === 'Ada Salon'
                && $mail->email === 'ada@example.com'
                && str_contains($mail->message, 'Business plan');
        });
    }

    public function test_missing_fields_return_422_and_send_no_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', ['name' => '', 'email' => 'not-an-email', 'message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'message']);

        Mail::assertNothingSent();
    }

    public function test_message_over_max_length_is_rejected(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => str_repeat('x', 5001),
        ])->assertStatus(422)->assertJsonValidationErrors(['message']);
    }

    public function test_endpoint_is_rate_limited_after_five_requests_per_minute(): void
    {
        Mail::fake();
        $payload = ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello there'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/contact', $payload)->assertOk();
        }

        $this->postJson('/api/contact', $payload)->assertStatus(429);
    }
}
