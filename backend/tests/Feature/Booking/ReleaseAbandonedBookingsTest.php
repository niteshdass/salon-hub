<?php

namespace Tests\Feature\Booking;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * bookings:release-abandoned cancels bookings whose online deposit was started
 * but never completed, freeing the held slot. Only abandoned gateway sessions
 * are released — manual transfers stay for owner review, and anything already
 * paid is left untouched. Default TTL is 30 minutes.
 */
class ReleaseAbandonedBookingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Branch $branch;

    private Service $service;

    private User $staff;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Abandon Salon', 'slug' => 'abandon',
            'email' => 'owner@abandon.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $this->branch = Branch::create(['organization_id' => $this->org->id, 'name' => 'Main']);
        $this->service = Service::create([
            'organization_id' => $this->org->id, 'name' => 'Colour',
            'duration' => 30, 'price' => 50, 'status' => 'active',
        ]);
        $this->staff = User::create([
            'organization_id' => $this->org->id, 'name' => 'Stylist',
            'email' => 'stylist@abandon.test', 'password' => 'secret1234',
            'role' => 'staff', 'status' => 'active',
        ]);
        $this->customer = Customer::create([
            'organization_id' => $this->org->id, 'name' => 'Casey', 'phone' => '555-1000',
        ]);
    }

    /**
     * A pending booking created $ageMinutes ago, with an optional payment.
     *
     * @param  array<string, mixed>|null  $payment
     */
    private function booking(int $ageMinutes, ?array $payment): Appointment
    {
        $appointment = Appointment::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'staff_id' => $this->staff->id,
            'booking_date' => Carbon::parse('+3 days')->format('Y-m-d'),
            'start_time' => '11:00:00', 'end_time' => '11:30:00',
            'price' => 50, 'status' => AppointmentStatus::PENDING->value,
        ]);

        if ($payment !== null) {
            $appointment->payments()->create(array_merge(
                ['organization_id' => $this->org->id, 'amount' => 10],
                $payment,
            ));
        }

        $stamp = Carbon::now()->subMinutes($ageMinutes);
        $appointment->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();

        return $appointment;
    }

    private function gatewayPending(): array
    {
        return [
            'method' => PaymentMethod::ONLINE, 'status' => PaymentStatus::PENDING,
            'source' => PaymentSource::GATEWAY, 'transaction_id' => 'SH'.strtoupper(Str::random(10)),
        ];
    }

    public function test_it_cancels_a_stale_gateway_booking_that_was_never_paid(): void
    {
        $appointment = $this->booking(60, $this->gatewayPending());

        $this->artisan('bookings:release-abandoned')->assertExitCode(0);

        $this->assertSame('cancelled', $appointment->fresh()->status->value);
    }

    public function test_it_leaves_a_recent_gateway_booking_alone(): void
    {
        // Still inside the TTL window — the customer may be mid-checkout.
        $appointment = $this->booking(5, $this->gatewayPending());

        $this->artisan('bookings:release-abandoned')->assertExitCode(0);

        $this->assertSame('pending', $appointment->fresh()->status->value);
    }

    public function test_it_leaves_a_paid_gateway_booking_alone(): void
    {
        $appointment = $this->booking(60, [
            'method' => PaymentMethod::ONLINE, 'status' => PaymentStatus::VERIFIED,
            'source' => PaymentSource::GATEWAY, 'transaction_id' => 'SHPAID',
        ]);

        $this->artisan('bookings:release-abandoned')->assertExitCode(0);

        $this->assertSame('pending', $appointment->fresh()->status->value);
    }

    public function test_it_leaves_a_manual_pending_booking_alone(): void
    {
        // Manual bank transfers await owner review — never auto-released.
        $appointment = $this->booking(60, [
            'method' => PaymentMethod::BANK_TRANSFER, 'status' => PaymentStatus::PENDING,
            'source' => PaymentSource::PUBLIC_MANUAL, 'reference' => 'TXN-1',
        ]);

        $this->artisan('bookings:release-abandoned')->assertExitCode(0);

        $this->assertSame('pending', $appointment->fresh()->status->value);
    }
}
