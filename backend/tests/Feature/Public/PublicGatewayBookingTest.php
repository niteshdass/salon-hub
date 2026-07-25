<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SSLCommerz online-deposit flow. No real network: the gateway's initiate and
 * validation APIs are faked. Credentials live only in the encrypted per-org
 * settings — never in code.
 */
class PublicGatewayBookingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Active org with a branch, a $50 service, and a staff member working
     * 09:00–17:00 every day, plus a sandbox SSLCommerz deposit policy.
     *
     * @return array<string, mixed>
     */
    private function scaffold(string $slug, array $payment): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug,
            'email' => "owner@{$slug}.test", 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Colour',
            'duration' => 30, 'price' => 50, 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist',
            'email' => "stylist@{$slug}.test", 'password' => 'secret1234',
            'role' => 'staff', 'status' => 'active',
        ]);
        StaffProfile::create([
            'user_id' => $staff->id, 'designation' => 'Senior',
            'working_days_json' => [1, 2, 3, 4, 5, 6, 7],
            'working_hours_json' => ['start' => '09:00', 'end' => '17:00'],
        ]);
        $staff->services()->attach($service->id);

        PaymentSetting::create(['organization_id' => $org->id] + $payment);

        return compact('org', 'branch', 'service', 'staff');
    }

    /** A sandbox gateway policy charging a 20% (= $10) deposit. */
    private function gatewayPolicy(array $overrides = []): array
    {
        return array_merge([
            'deposit_type' => 'percent', 'deposit_value' => 20,
            'manual_enabled' => false,
            'gateway' => 'sslcommerz', 'gateway_sandbox' => true,
            'credentials' => ['store_id' => 'sandbox_store', 'store_passwd' => 'sandbox_pass'],
        ], $overrides);
    }

    private function nextMonday(): string
    {
        return Carbon::parse('next monday')->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $ctx, array $overrides = []): array
    {
        return array_merge([
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            'customer' => ['name' => 'Gateway Gina', 'phone' => '555-3030', 'email' => 'gina@example.test'],
        ], $overrides);
    }

    private function fakeInitiateSuccess(): void
    {
        Http::fake([
            'sandbox.sslcommerz.com/gwprocess/*' => Http::response([
                'status' => 'SUCCESS',
                'sessionkey' => 'sess-123',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/testcdeXYZ',
            ]),
        ]);
    }

    public function test_choosing_the_gateway_creates_a_pending_online_payment_and_returns_a_redirect_url(): void
    {
        $ctx = $this->scaffold('gw-book', $this->gatewayPolicy());
        $this->fakeInitiateSuccess();

        $response = $this->postJson('/api/public/gw-book/book', $this->payload($ctx, [
            'payment_method' => 'gateway',
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.gateway_url', 'https://sandbox.sslcommerz.com/EasyCheckOut/testcdeXYZ');
        // The deposit ($10) is held pending online until the gateway confirms.
        $response->assertJsonPath('data.payment.amount_pending', '10.00');
        $response->assertJsonPath('data.payment.amount_paid', '0.00');

        $payment = Payment::first();
        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment->status->value);
        $this->assertSame('gateway', $payment->source->value);
        $this->assertNotEmpty($payment->transaction_id);
        $this->assertSame('10.00', $payment->amount);

        // The initiate call carried the store credentials and the deposit total.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'gwprocess')
                && $request['store_id'] === 'sandbox_store'
                && $request['store_passwd'] === 'sandbox_pass'
                && (string) $request['total_amount'] === '10.00';
        });
    }

    /** Book online, returning the pending gateway payment created for it. */
    private function bookOnline(string $slug): Payment
    {
        $ctx = $this->scaffold($slug, $this->gatewayPolicy());
        $this->fakeInitiateSuccess();

        $this->postJson("/api/public/{$slug}/book", $this->payload($ctx, [
            'payment_method' => 'gateway',
        ]))->assertStatus(201);

        return Payment::firstOrFail();
    }

    public function test_a_validated_callback_marks_the_online_deposit_verified_and_returns_to_the_manage_page(): void
    {
        $payment = $this->bookOnline('gw-success');
        $tran = $payment->transaction_id;

        // Server-to-server validation confirms the transaction is real and paid.
        Http::fake([
            'sandbox.sslcommerz.com/validator/*' => Http::response([
                'status' => 'VALID',
                'tran_id' => $tran,
                'amount' => '10.00',
                'currency' => 'USD',
                'val_id' => 'VAL-OK',
            ]),
        ]);

        $response = $this->post("/api/public/gw-success/payment/{$tran}/callback/success", [
            'val_id' => 'VAL-OK',
            'tran_id' => $tran,
            'status' => 'VALID',
        ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/book/gw-success/manage/', $location);
        $this->assertStringContainsString('payment=success', $location);

        $this->assertSame('verified', $payment->fresh()->status->value);
    }

    public function test_a_validated_callback_confirms_the_booking(): void
    {
        $payment = $this->bookOnline('gw-confirm');
        $tran = $payment->transaction_id;
        $this->assertSame('pending', $payment->appointment->status->value);

        Http::fake([
            'sandbox.sslcommerz.com/validator/*' => Http::response([
                'status' => 'VALID',
                'tran_id' => $tran,
                'amount' => '10.00',
                'currency' => 'USD',
                'val_id' => 'VAL-OK',
            ]),
        ]);

        $this->post("/api/public/gw-confirm/payment/{$tran}/callback/success", [
            'val_id' => 'VAL-OK',
            'tran_id' => $tran,
        ])->assertRedirect();

        // A captured online deposit confirms the booking outright.
        $this->assertSame('confirmed', $payment->appointment->fresh()->status->value);
    }

    public function test_a_failed_callback_does_not_confirm_the_booking(): void
    {
        $payment = $this->bookOnline('gw-fail-status');
        $tran = $payment->transaction_id;

        $this->post("/api/public/gw-fail-status/payment/{$tran}/callback/fail", [
            'tran_id' => $tran,
        ])->assertRedirect();

        $this->assertSame('pending', $payment->appointment->fresh()->status->value);
    }

    public function test_the_gateway_session_registers_an_ipn_url(): void
    {
        $this->bookOnline('gw-ipn-url');

        // SSLCommerz needs a server-to-server IPN target so a captured payment
        // is recorded even when the customer never returns to the browser.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'gwprocess')
                && str_contains((string) $request['ipn_url'], '/payment/')
                && str_ends_with((string) $request['ipn_url'], '/ipn');
        });
    }

    public function test_an_ipn_notification_verifies_and_confirms_the_booking(): void
    {
        $payment = $this->bookOnline('gw-ipn');
        $tran = $payment->transaction_id;
        $this->assertSame('pending', $payment->appointment->status->value);

        Http::fake([
            'sandbox.sslcommerz.com/validator/*' => Http::response([
                'status' => 'VALID',
                'tran_id' => $tran,
                'amount' => '10.00',
                'currency' => 'USD',
                'val_id' => 'VAL-IPN',
            ]),
        ]);

        // Server-to-server: SSLCommerz POSTs here directly. No browser, so the
        // response is a plain acknowledgement, never a redirect.
        $response = $this->post("/api/public/gw-ipn/payment/{$tran}/ipn", [
            'val_id' => 'VAL-IPN',
            'tran_id' => $tran,
            'status' => 'VALID',
        ]);

        $response->assertOk();
        $this->assertSame('verified', $payment->fresh()->status->value);
        $this->assertSame('confirmed', $payment->appointment->fresh()->status->value);
    }

    public function test_an_ipn_with_a_tampered_amount_leaves_the_payment_pending(): void
    {
        $payment = $this->bookOnline('gw-ipn-tamper');
        $tran = $payment->transaction_id;

        Http::fake([
            'sandbox.sslcommerz.com/validator/*' => Http::response([
                'status' => 'VALID',
                'tran_id' => $tran,
                'amount' => '1.00',
                'currency' => 'USD',
                'val_id' => 'VAL-IPN-BAD',
            ]),
        ]);

        $response = $this->post("/api/public/gw-ipn-tamper/payment/{$tran}/ipn", [
            'val_id' => 'VAL-IPN-BAD',
            'tran_id' => $tran,
        ]);

        // Acknowledged (200 so the gateway stops retrying) but not captured.
        $response->assertOk();
        $this->assertSame('pending', $payment->fresh()->status->value);
        $this->assertSame('pending', $payment->appointment->fresh()->status->value);
    }

    public function test_a_repeated_ipn_notification_is_idempotent(): void
    {
        $payment = $this->bookOnline('gw-ipn-twice');
        $tran = $payment->transaction_id;

        Http::fake([
            'sandbox.sslcommerz.com/validator/*' => Http::response([
                'status' => 'VALID',
                'tran_id' => $tran,
                'amount' => '10.00',
                'currency' => 'USD',
                'val_id' => 'VAL-IPN',
            ]),
        ]);

        // The gateway may deliver the same IPN more than once, and it may race
        // the browser success callback — processing twice must be harmless.
        for ($i = 0; $i < 2; $i++) {
            $this->post("/api/public/gw-ipn-twice/payment/{$tran}/ipn", [
                'val_id' => 'VAL-IPN',
                'tran_id' => $tran,
            ])->assertOk();
        }

        $this->assertSame('verified', $payment->fresh()->status->value);
        $this->assertSame('confirmed', $payment->appointment->fresh()->status->value);
        $this->assertSame(1, $payment->appointment->payments()->count());
    }

    public function test_a_tampered_amount_fails_validation_and_leaves_the_payment_pending(): void
    {
        $payment = $this->bookOnline('gw-tamper');
        $tran = $payment->transaction_id;

        // Gateway reports a smaller amount than the deposit owed — reject it.
        Http::fake([
            'sandbox.sslcommerz.com/validator/*' => Http::response([
                'status' => 'VALID',
                'tran_id' => $tran,
                'amount' => '1.00',
                'currency' => 'USD',
                'val_id' => 'VAL-BAD',
            ]),
        ]);

        $response = $this->post("/api/public/gw-tamper/payment/{$tran}/callback/success", [
            'val_id' => 'VAL-BAD',
            'tran_id' => $tran,
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('payment=failed', $response->headers->get('Location'));
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_a_failed_callback_leaves_the_payment_pending(): void
    {
        $payment = $this->bookOnline('gw-fail');
        $tran = $payment->transaction_id;

        $response = $this->post("/api/public/gw-fail/payment/{$tran}/callback/fail", [
            'tran_id' => $tran,
            'status' => 'FAILED',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('payment=failed', $response->headers->get('Location'));
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_a_cancelled_callback_leaves_the_payment_pending(): void
    {
        $payment = $this->bookOnline('gw-cancel');
        $tran = $payment->transaction_id;

        $response = $this->post("/api/public/gw-cancel/payment/{$tran}/callback/cancel", [
            'tran_id' => $tran,
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('payment=cancelled', $response->headers->get('Location'));
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_requesting_the_gateway_when_it_is_not_configured_is_rejected(): void
    {
        // Deposit required, only manual transfer enabled — no online option.
        $ctx = $this->scaffold('gw-off', [
            'deposit_type' => 'percent', 'deposit_value' => 20,
            'manual_enabled' => true, 'manual_account_number' => 'ACME-1',
            'gateway' => 'none',
        ]);

        $response = $this->postJson('/api/public/gw-off/book', $this->payload($ctx, [
            'payment_method' => 'gateway',
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('appointments', ['staff_id' => $ctx['staff']->id]);
    }

    public function test_when_both_methods_are_offered_the_customer_must_choose_one(): void
    {
        $ctx = $this->scaffold('gw-both', $this->gatewayPolicy([
            'manual_enabled' => true, 'manual_account_number' => 'ACME-2',
        ]));

        $response = $this->postJson('/api/public/gw-both/book', $this->payload($ctx));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('appointments', ['staff_id' => $ctx['staff']->id]);
    }
}
