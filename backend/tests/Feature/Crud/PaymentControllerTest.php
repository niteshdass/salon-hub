<?php

namespace Tests\Feature\Crud;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An org with an owner, a staff member, and one $40 appointment.
     *
     * @return array<string, mixed>
     */
    private function scaffold(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
        $owner = User::create([
            'organization_id' => $org->id, 'name' => 'Owner',
            'email' => "owner@{$slug}.test", 'password' => 'secret1234',
            'role' => 'owner', 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist',
            'email' => "stylist@{$slug}.test", 'password' => 'secret1234',
            'role' => 'staff', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);
        $appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'booking_date' => Carbon::tomorrow()->toDateString(),
            'start_time' => '10:00:00', 'end_time' => '10:30:00',
            'price' => 40, 'status' => 'completed',
        ]);

        return [
            'org' => $org, 'owner' => $owner, 'staff' => $staff,
            'ownerToken' => $owner->createToken('api')->plainTextToken,
            'staffToken' => $staff->createToken('api')->plainTextToken,
            'appointment' => $appointment,
        ];
    }

    /**
     * Bearer-authenticate as a role. Sanctum's RequestGuard memoizes the
     * resolved user per test app, so a second token in the same test is
     * ignored unless the guards are forgotten first.
     */
    private function as(array $ctx, string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_records_a_payment_against_an_appointment(): void
    {
        $ctx = $this->scaffold('recpay');

        $response = $this->withToken($ctx['staffToken'])->postJson(
            "/api/appointments/{$ctx['appointment']->id}/payments",
            ['amount' => 25, 'method' => 'cash', 'reference' => 'till-1'],
        );

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '25.00');
        $response->assertJsonPath('data.method', 'cash');
        $response->assertJsonPath('data.reference', 'till-1');
        $response->assertJsonPath('data.recorded_by', 'Stylist');

        $this->assertDatabaseHas('payments', [
            'organization_id' => $ctx['org']->id,
            'appointment_id' => $ctx['appointment']->id,
            'amount' => 25,
            'method' => 'cash',
            'recorded_by' => $ctx['staff']->id,
        ]);
    }

    public function test_lists_payments_for_an_appointment_newest_first(): void
    {
        $ctx = $this->scaffold('listpay');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";

        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 10, 'method' => 'cash'])->assertCreated();
        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 30, 'method' => 'card'])->assertCreated();

        $response = $this->withToken($ctx['staffToken'])->getJson($url);

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.amount', '30.00');
        $response->assertJsonPath('data.1.amount', '10.00');
    }

    public function test_listing_exposes_the_gateway_transaction_id_for_reconciliation(): void
    {
        $ctx = $this->scaffold('gwlist');

        // An online deposit captured by the gateway, as the callback records it.
        $ctx['appointment']->payments()->create([
            'organization_id' => $ctx['org']->id,
            'amount' => 10,
            'method' => PaymentMethod::ONLINE,
            'status' => PaymentStatus::VERIFIED,
            'source' => PaymentSource::GATEWAY,
            'transaction_id' => 'SHABC123',
        ]);

        $response = $this->withToken($ctx['ownerToken'])
            ->getJson("/api/appointments/{$ctx['appointment']->id}/payments");

        $response->assertOk();
        // The owner needs the transaction id to reconcile against SSLCommerz.
        $response->assertJsonPath('data.0.source', 'gateway');
        $response->assertJsonPath('data.0.transaction_id', 'SHABC123');
    }

    /** A captured online deposit on the appointment, ready to refund. */
    private function gatewayDeposit(array $ctx): Payment
    {
        return $ctx['appointment']->payments()->create([
            'organization_id' => $ctx['org']->id,
            'amount' => 10,
            'method' => PaymentMethod::ONLINE,
            'status' => PaymentStatus::VERIFIED,
            'source' => PaymentSource::GATEWAY,
            'transaction_id' => 'SHABC123',
            'bank_tran_id' => 'BANK-77',
        ]);
    }

    public function test_owner_refunds_a_gateway_deposit(): void
    {
        $ctx = $this->scaffold('gwrefund');
        // The org needs SSLCommerz settings so the refund can be addressed.
        PaymentSetting::create([
            'organization_id' => $ctx['org']->id,
            'deposit_type' => 'percent', 'deposit_value' => 20,
            'gateway' => 'sslcommerz', 'gateway_sandbox' => true,
            'credentials' => ['store_id' => 'store', 'store_passwd' => 'pass'],
        ]);
        $payment = $this->gatewayDeposit($ctx);

        Http::fake([
            'sandbox.sslcommerz.com/validator/*' => Http::response([
                'APIConnect' => 'DONE', 'status' => 'success', 'refund_ref_id' => 'RF-9',
            ]),
        ]);

        $response = $this->withToken($ctx['ownerToken'])->postJson(
            "/api/appointments/{$ctx['appointment']->id}/payments/{$payment->id}/refund",
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'refunded');

        $fresh = $payment->fresh();
        $this->assertSame('refunded', $fresh->status->value);
        $this->assertSame('RF-9', $fresh->refund_ref);
        $this->assertNotNull($fresh->refunded_at);

        // The refund was addressed to the gateway's bank_tran_id.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'merchantTransIDvalidationAPI.php')
            && $request['bank_tran_id'] === 'BANK-77'
            && $request['refund_amount'] === '10.00');
    }

    public function test_staff_cannot_refund_a_gateway_deposit(): void
    {
        $ctx = $this->scaffold('gwrefund-staff');
        $payment = $this->gatewayDeposit($ctx);

        $this->withToken($ctx['staffToken'])->postJson(
            "/api/appointments/{$ctx['appointment']->id}/payments/{$payment->id}/refund",
        )->assertStatus(403);

        $this->assertSame('verified', $payment->fresh()->status->value);
    }

    public function test_a_non_gateway_payment_cannot_be_refunded(): void
    {
        $ctx = $this->scaffold('gwrefund-cash');
        $payment = $ctx['appointment']->payments()->create([
            'organization_id' => $ctx['org']->id, 'amount' => 10,
            'method' => PaymentMethod::CASH,
            'status' => PaymentStatus::VERIFIED,
            'source' => PaymentSource::STAFF,
        ]);

        $this->withToken($ctx['ownerToken'])->postJson(
            "/api/appointments/{$ctx['appointment']->id}/payments/{$payment->id}/refund",
        )->assertStatus(422);

        $this->assertSame('verified', $payment->fresh()->status->value);
    }

    public function test_a_declined_gateway_refund_leaves_the_payment_verified(): void
    {
        $ctx = $this->scaffold('gwrefund-fail');
        PaymentSetting::create([
            'organization_id' => $ctx['org']->id,
            'deposit_type' => 'percent', 'deposit_value' => 20,
            'gateway' => 'sslcommerz', 'gateway_sandbox' => true,
            'credentials' => ['store_id' => 'store', 'store_passwd' => 'pass'],
        ]);
        $payment = $this->gatewayDeposit($ctx);

        Http::fake([
            'sandbox.sslcommerz.com/validator/*' => Http::response([
                'APIConnect' => 'DONE', 'status' => 'failed', 'errorReason' => 'Already refunded',
            ]),
        ]);

        $this->withToken($ctx['ownerToken'])->postJson(
            "/api/appointments/{$ctx['appointment']->id}/payments/{$payment->id}/refund",
        )->assertStatus(422);

        // Nothing was returned, so the deposit is still on the books.
        $this->assertSame('verified', $payment->fresh()->status->value);
        $this->assertNull($payment->fresh()->refunded_at);
    }

    public function test_amount_and_method_are_validated(): void
    {
        $ctx = $this->scaffold('valpay');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";

        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 0, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 10, 'method' => 'bitcoin'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
    }

    /**
     * Until this cap, nothing server-side stood between a typed Amount and
     * a negative balance — only a client-side clamp in the same form that
     * now also has to accept a tip on an already-settled booking.
     */
    public function test_overpaying_the_balance_is_rejected(): void
    {
        $ctx = $this->scaffold('overpay');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";

        $response = $this->withToken($ctx['staffToken'])->postJson($url, [
            'amount' => 5000, 'tip_amount' => 10, 'method' => 'cash',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertDatabaseMissing('payments', ['appointment_id' => $ctx['appointment']->id]);
    }

    public function test_paying_exactly_the_balance_succeeds(): void
    {
        $ctx = $this->scaffold('exactpay');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";

        $response = $this->withToken($ctx['staffToken'])->postJson($url, [
            'amount' => 40, 'method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertSame('0.00', $ctx['appointment']->fresh()->load('payments')->balanceDue());
    }

    /** A tip is never bounded by the balance, even on a settled booking. */
    public function test_tip_only_payment_on_a_settled_booking_is_uncapped(): void
    {
        $ctx = $this->scaffold('tiponly');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";

        $this->withToken($ctx['staffToken'])->postJson($url, ['amount' => 40, 'method' => 'cash'])->assertCreated();

        $response = $this->withToken($ctx['staffToken'])->postJson($url, [
            'amount' => 0, 'tip_amount' => 500, 'method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertSame('500.00', $ctx['appointment']->fresh()->load('payments')->tipsCollected());
    }

    public function test_a_tip_larger_than_the_balance_is_still_accepted(): void
    {
        $ctx = $this->scaffold('bigtip');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";

        $response = $this->withToken($ctx['staffToken'])->postJson($url, [
            'amount' => 5, 'tip_amount' => 100, 'method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertSame('100.00', $ctx['appointment']->fresh()->load('payments')->tipsCollected());
    }

    public function test_owner_deletes_a_payment_but_staff_cannot(): void
    {
        $ctx = $this->scaffold('delpay');
        $url = "/api/appointments/{$ctx['appointment']->id}/payments";
        $paymentId = $this->withToken($ctx['staffToken'])
            ->postJson($url, ['amount' => 15, 'method' => 'cash'])->json('data.id');

        $this->as($ctx, $ctx['staffToken'])->deleteJson("{$url}/{$paymentId}")->assertForbidden();
        $this->as($ctx, $ctx['ownerToken'])->deleteJson("{$url}/{$paymentId}")->assertNoContent();

        $this->assertDatabaseMissing('payments', ['id' => $paymentId]);
    }

    public function test_cannot_record_a_payment_against_another_tenants_appointment(): void
    {
        $mine = $this->scaffold('mine-pay');
        $theirs = $this->scaffold('their-pay');

        // My token, their appointment id: the tenant scope hides it → 404.
        $this->withToken($mine['staffToken'])->postJson(
            "/api/appointments/{$theirs['appointment']->id}/payments",
            ['amount' => 5, 'method' => 'cash'],
        )->assertNotFound();
    }

    public function test_a_payment_bound_to_another_appointment_is_not_deletable_here(): void
    {
        $ctx = $this->scaffold('mixpay');
        $other = Appointment::create([
            'organization_id' => $ctx['org']->id,
            'branch_id' => $ctx['appointment']->branch_id,
            'customer_id' => $ctx['appointment']->customer_id,
            'staff_id' => $ctx['staff']->id,
            'booking_date' => Carbon::tomorrow()->toDateString(),
            'start_time' => '11:00:00', 'end_time' => '11:30:00',
            'price' => 40, 'status' => 'completed',
        ]);
        $payment = Payment::create([
            'organization_id' => $ctx['org']->id,
            'appointment_id' => $other->id,
            'amount' => 20, 'method' => 'cash',
        ]);

        // The payment exists, but not under THIS appointment → 404, not 204.
        $this->withToken($ctx['ownerToken'])->deleteJson(
            "/api/appointments/{$ctx['appointment']->id}/payments/{$payment->id}",
        )->assertNotFound();
    }
}
