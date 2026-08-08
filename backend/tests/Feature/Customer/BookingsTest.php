<?php

namespace Tests\Feature\Customer;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug,
            'email' => "owner@{$slug}.test", 'subscription_plan' => 'free', 'status' => 'active',
        ]);
    }

    private function makeStaff(Organization $org, string $name = 'Sam'): User
    {
        $staff = User::create(['organization_id' => $org->id, 'name' => $name, 'email' => Str::random(6)."@{$org->slug}.test", 'password' => 'secret1234', 'role' => 'staff', 'status' => 'active']);
        StaffProfile::create(['user_id' => $staff->id, 'designation' => 'Stylist', 'working_days_json' => [1, 2, 3, 4, 5], 'working_hours_json' => ['start' => '09:00', 'end' => '17:00']]);

        return $staff;
    }

    /** An account with one linked customer row per given org, and a token. */
    private function account(string $email): CustomerAccount
    {
        return CustomerAccount::create(['name' => 'Jane', 'email' => $email, 'email_verified_at' => now()]);
    }

    private function tokenFor(CustomerAccount $account): string
    {
        return $account->createToken('customer')->plainTextToken;
    }

    private function makeBooking(Organization $org, CustomerAccount $account, array $o = []): Appointment
    {
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create(['organization_id' => $org->id, 'name' => $o['service'] ?? 'Haircut', 'duration' => 30, 'price' => $o['price'] ?? 40, 'status' => 'active']);
        $staff = $this->makeStaff($org, $o['staff'] ?? 'Sam');
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Jane', 'phone' => Str::random(6), 'email' => $account->email, 'customer_account_id' => $account->id]);

        $appointment = Appointment::create([
            'organization_id' => $org->id, 'public_token' => (string) Str::uuid(),
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'staff_id' => $staff->id, 'service_id' => $service->id,
            'booking_date' => $o['date'] ?? '2026-08-10', 'start_time' => $o['start_time'] ?? '10:00:00', 'end_time' => '10:30:00',
            'price' => $o['price'] ?? 40, 'status' => $o['status'] ?? 'confirmed',
        ]);

        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $service->id,
            'name' => $service->name, 'price' => $service->price, 'duration' => $service->duration, 'sort_order' => 0,
        ]);

        return $appointment;
    }

    public function test_lists_own_bookings_split_upcoming_and_past(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        // Upcoming: future + confirmed. Past: completed.
        $this->makeBooking($org, $account, ['date' => '2999-01-01', 'status' => 'confirmed', 'service' => 'Future Cut']);
        $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed', 'service' => 'Old Cut']);

        $res = $this->withToken($this->tokenFor($account))->getJson('/api/customer/bookings');

        $res->assertOk()
            ->assertJsonStructure(['data' => [
                'upcoming' => [['id', 'salon' => ['id', 'name', 'slug'], 'services', 'staff', 'branch', 'booking_date', 'start_time', 'end_time', 'status', 'price', 'amount_paid', 'balance_due', 'can_manage', 'can_review', 'review']],
                'past' => [['id', 'services']],
            ]]);
        $this->assertCount(1, $res->json('data.upcoming'));
        $this->assertCount(1, $res->json('data.past'));
        $this->assertSame(['Future Cut'], $res->json('data.upcoming.0.services'));
        $this->assertTrue($res->json('data.upcoming.0.can_manage'));
    }

    public function test_aggregates_across_salons(): void
    {
        $orgA = $this->makeOrg('acme');
        $orgB = $this->makeOrg('glow');
        $account = $this->account('jane@x.test');
        $this->makeBooking($orgA, $account, ['date' => '2999-01-01']);
        $this->makeBooking($orgB, $account, ['date' => '2999-02-02']);

        $res = $this->withToken($this->tokenFor($account))->getJson('/api/customer/bookings');
        $res->assertOk();
        $this->assertCount(2, $res->json('data.upcoming'));
        $slugs = collect($res->json('data.upcoming'))->pluck('salon.slug')->sort()->values()->all();
        $this->assertSame(['acme', 'glow'], $slugs);
    }

    public function test_isolation_account_sees_only_its_own_bookings(): void
    {
        $org = $this->makeOrg('acme');
        $mine = $this->account('jane@x.test');
        $theirs = $this->account('bob@x.test');
        $this->makeBooking($org, $mine, ['date' => '2999-01-01', 'service' => 'Mine']);
        $this->makeBooking($org, $theirs, ['date' => '2999-01-01', 'service' => 'Theirs']);

        $res = $this->withToken($this->tokenFor($mine))->getJson('/api/customer/bookings');
        $res->assertOk();
        $services = collect($res->json('data.upcoming'))->pluck('services')->flatten()->all();
        $this->assertSame(['Mine'], $services);
    }

    public function test_staff_token_rejected_on_customer_bookings_route(): void
    {
        $org = $this->makeOrg('acme');
        $staff = User::create(['organization_id' => $org->id, 'name' => 'Owner', 'email' => 'o@acme.test', 'password' => 'secret1234', 'role' => 'owner', 'status' => 'active']);
        $this->withToken($staff->createToken('api')->plainTextToken)->getJson('/api/customer/bookings')
            ->assertUnauthorized();
    }

    public function test_completed_without_review_can_be_reviewed(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);

        $res = $this->withToken($this->tokenFor($account))->getJson('/api/customer/bookings');
        $this->assertTrue($res->json('data.past.0.can_review'));
        $this->assertNull($res->json('data.past.0.review'));
    }

    public function test_reviewed_booking_shows_review_and_cannot_review_again(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);
        Review::create(['organization_id' => $org->id, 'appointment_id' => $appt->id, 'staff_id' => $appt->staff_id, 'rating' => 5, 'comment' => 'Great', 'reviewer_name' => 'Jane', 'status' => 'published']);

        $res = $this->withToken($this->tokenFor($account))->getJson('/api/customer/bookings');
        $this->assertFalse($res->json('data.past.0.can_review'));
        $this->assertSame(5, $res->json('data.past.0.review.rating'));
    }

    public function test_cancel_owned_upcoming_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2999-01-01', 'status' => 'confirmed']);

        $res = $this->withToken($this->tokenFor($account))->postJson("/api/customer/bookings/{$appt->id}/cancel");

        $res->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', $appt->fresh()->status->value);
    }

    public function test_cannot_cancel_completed_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);

        $this->withToken($this->tokenFor($account))->postJson("/api/customer/bookings/{$appt->id}/cancel")
            ->assertStatus(422);
    }

    public function test_cannot_cancel_foreign_booking(): void
    {
        $org = $this->makeOrg('acme');
        $mine = $this->account('jane@x.test');
        $theirs = $this->account('bob@x.test');
        $appt = $this->makeBooking($org, $theirs, ['date' => '2999-01-01', 'status' => 'confirmed']);

        $this->withToken($this->tokenFor($mine))->postJson("/api/customer/bookings/{$appt->id}/cancel")
            ->assertNotFound();
    }

    public function test_slots_and_reschedule_owned_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        // Monday, in the future relative to nothing-scheduled; staff works Mon–Fri.
        $appt = $this->makeBooking($org, $account, ['date' => '2026-08-10', 'start_time' => '10:00:00', 'status' => 'confirmed']);
        $token = $this->tokenFor($account);

        $slots = $this->withToken($token)->getJson("/api/customer/bookings/{$appt->id}/slots?date=2026-08-10");
        $slots->assertOk()->assertJsonStructure(['data' => ['date', 'slots']]);
        $open = $slots->json('data.slots');
        $this->assertNotEmpty($open);

        $target = $open[count($open) - 1];
        $res = $this->withToken($token)->postJson("/api/customer/bookings/{$appt->id}/reschedule", ['date' => '2026-08-10', 'start_time' => $target]);
        $res->assertOk()->assertJsonPath('data.start_time', $target);
        $this->assertSame($target, substr($appt->fresh()->start_time, 0, 5));
    }

    public function test_reschedule_foreign_booking_is_404(): void
    {
        $org = $this->makeOrg('acme');
        $theirs = $this->account('bob@x.test');
        $mine = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $theirs, ['date' => '2026-08-10', 'status' => 'confirmed']);

        $this->withToken($this->tokenFor($mine))->postJson("/api/customer/bookings/{$appt->id}/reschedule", ['date' => '2026-08-10', 'start_time' => '11:00'])
            ->assertNotFound();
    }

    public function test_review_completed_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);

        $res = $this->withToken($this->tokenFor($account))->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 5, 'comment' => 'Loved it']);

        $res->assertCreated();
        $this->assertDatabaseHas('reviews', ['appointment_id' => $appt->id, 'rating' => 5, 'organization_id' => $org->id]);
    }

    public function test_cannot_review_non_completed_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2999-01-01', 'status' => 'confirmed']);

        $this->withToken($this->tokenFor($account))->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 5])
            ->assertStatus(422);
    }

    public function test_cannot_review_twice(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);
        $token = $this->tokenFor($account);
        $this->withToken($token)->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 5])->assertCreated();

        $this->withToken($token)->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 4])
            ->assertStatus(409);
    }

    public function test_cannot_review_foreign_booking(): void
    {
        $org = $this->makeOrg('acme');
        $theirs = $this->account('bob@x.test');
        $mine = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $theirs, ['date' => '2000-01-01', 'status' => 'completed']);

        $this->withToken($this->tokenFor($mine))->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 5])
            ->assertNotFound();
    }
}
