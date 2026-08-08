<?php

namespace Tests\Feature\Customer;

use App\Mail\CustomerLoginCodeMail;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinkingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug,
            'email' => "owner@{$slug}.test", 'subscription_plan' => 'free', 'status' => 'active',
        ]);
    }

    /**
     * Build a bookable ACTIVE org (branch + active service + staff assigned
     * to it), mirroring PublicBookingTest::scaffold(), so this test can drive
     * a real POST /api/public/{slug}/book instead of poking the DB directly.
     *
     * @return array<string, mixed>
     */
    private function scaffoldBookableOrg(string $slug): array
    {
        $org = $this->makeOrg($slug);

        $branch = Branch::create([
            'organization_id' => $org->id,
            'name' => 'Main',
            'city' => 'Metropolis',
            'address' => '1 High Street',
            'phone' => '+1 555 0000',
        ]);

        $service = Service::create([
            'organization_id' => $org->id,
            'name' => 'Haircut',
            'duration' => 30,
            'price' => 25,
            'status' => 'active',
        ]);

        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Stylist',
            'email' => "stylist@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        StaffProfile::create([
            'user_id' => $staff->id,
            'designation' => 'Senior Stylist',
            'working_days_json' => [1, 2, 3, 4, 5, 6, 7],
            'working_hours_json' => ['start' => '09:00', 'end' => '17:00'],
        ]);

        $staff->services()->attach($service->id);

        return compact('org', 'branch', 'service', 'staff');
    }

    private function loginCodeFor(string $email): string
    {
        Mail::fake();
        $this->postJson('/api/customer/auth/request-code', ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(CustomerLoginCodeMail::class, function ($m) use ($email, &$code) {
            if ($m->hasTo($email)) {
                $code = $m->code;

                return true;
            }

            return false;
        });

        return $code;
    }

    private function verify(string $email): void
    {
        $this->signIn($email);
    }

    /** Verify an email and return the issued customer API token. */
    private function signIn(string $email): string
    {
        $code = $this->loginCodeFor($email);
        $response = $this->postJson('/api/customer/auth/verify-code', ['email' => $email, 'code' => $code])->assertOk();

        return $response->json('token');
    }

    /** A fixed, deterministic future Monday (ISO weekday 1), staff works every day. */
    private function nextMonday(): string
    {
        return Carbon::parse('next monday')->format('Y-m-d');
    }

    public function test_verify_claims_matching_customer_rows_across_salons(): void
    {
        $orgA = $this->makeOrg('acme');
        $orgB = $this->makeOrg('glow');
        $mine1 = Customer::create(['organization_id' => $orgA->id, 'name' => 'Jane', 'phone' => '111', 'email' => 'jane@x.test']);
        $mine2 = Customer::create(['organization_id' => $orgB->id, 'name' => 'Jane', 'phone' => '222', 'email' => 'jane@x.test']);
        $notMine = Customer::create(['organization_id' => $orgA->id, 'name' => 'Other', 'phone' => '333', 'email' => 'other@x.test']);

        $this->verify('jane@x.test');

        $account = CustomerAccount::where('email', 'jane@x.test')->first();
        $this->assertSame($account->id, $mine1->fresh()->customer_account_id);
        $this->assertSame($account->id, $mine2->fresh()->customer_account_id);
        $this->assertNull($notMine->fresh()->customer_account_id);
    }

    public function test_verify_is_idempotent_and_links_rows_created_since_last_login(): void
    {
        $org = $this->makeOrg('acme');
        $this->verify('jane@x.test');
        $account = CustomerAccount::where('email', 'jane@x.test')->first();

        // A row created after the first login is picked up on the next verify.
        $late = Customer::create(['organization_id' => $org->id, 'name' => 'Jane', 'phone' => '444', 'email' => 'jane@x.test']);
        $this->verify('jane@x.test');

        $this->assertSame($account->id, $late->fresh()->customer_account_id);
        $this->assertSame(1, CustomerAccount::where('email', 'jane@x.test')->count());
    }

    /**
     * Public::BookingController::book() fresh-attaches a new Customer row to
     * a verified CustomerAccount by comparing emails with `=`, which is
     * case-sensitive on sqlite. Customer.email must therefore always be
     * stored lowercase so a booking made with mixed-case input still links.
     */
    public function test_public_booking_with_mixed_case_email_links_to_verified_account(): void
    {
        // A verified account already exists, stored lowercase (AuthController
        // lowercases before writing CustomerAccount.email).
        $account = CustomerAccount::create(['name' => 'Jane', 'email' => 'jane@x.test', 'phone' => null]);
        $account->forceFill(['email_verified_at' => now()])->save();

        $ctx = $this->scaffoldBookableOrg('mixedcase-salon');

        $response = $this->postJson('/api/public/mixedcase-salon/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            'customer' => ['name' => 'Jane', 'phone' => '555-4242', 'email' => 'Jane@X.test'],
        ]);
        $response->assertCreated();

        $customer = Customer::where('phone', '555-4242')->first();
        $this->assertNotNull($customer);

        // Mutator normalizes storage regardless of input casing.
        $this->assertSame('jane@x.test', $customer->email);

        // Fresh-attach linked the new customer row to the existing verified
        // account despite the mixed-case input — this is the bug fix.
        $this->assertSame($account->id, $customer->customer_account_id);
    }

    /**
     * The signed-in customer IS the booker. Matching emails is a fallback for
     * anonymous bookings; when a customer token is on the request their account
     * owns the booking outright, whatever address the form carried.
     */
    public function test_booking_while_signed_in_links_to_the_account_despite_a_different_email(): void
    {
        $token = $this->signIn('jane@x.test');
        $account = CustomerAccount::where('email', 'jane@x.test')->first();

        $ctx = $this->scaffoldBookableOrg('signedin-salon');

        $this->withToken($token)->postJson('/api/public/signedin-salon/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            // A different address than the account's — the session wins.
            'customer' => ['name' => 'Jane', 'phone' => '555-1111', 'email' => 'typo@elsewhere.test'],
        ])->assertCreated();

        $customer = Customer::where('phone', '555-1111')->first();
        $this->assertSame($account->id, $customer->customer_account_id);

        // And it reaches the dashboard, which is the whole point.
        $this->withToken($token)->getJson('/api/customer/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming');
    }

    /**
     * A signed-in booker never re-types their name: the account supplies it, so
     * the wizard can drop the field entirely.
     */
    public function test_signed_in_booking_needs_no_name_and_takes_it_from_the_account(): void
    {
        $token = $this->signIn('jane@x.test');
        $account = CustomerAccount::where('email', 'jane@x.test')->first();
        $account->forceFill(['name' => 'Jane Doe'])->save();

        $ctx = $this->scaffoldBookableOrg('nameless-salon');

        $this->withToken($token)->postJson('/api/public/nameless-salon/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            'customer' => ['phone' => '555-2222'],
        ])->assertCreated();

        $customer = Customer::where('phone', '555-2222')->first();
        $this->assertSame('Jane Doe', $customer->name);
        $this->assertSame('jane@x.test', $customer->email);
        $this->assertSame($account->id, $customer->customer_account_id);

        // The account learns the phone it booked with, for next time.
        $this->assertSame('555-2222', $account->fresh()->phone);
    }

    /** An anonymous booking still has to carry a name — nobody supplies one. */
    public function test_anonymous_booking_still_requires_a_name(): void
    {
        $ctx = $this->scaffoldBookableOrg('anon-salon');

        $this->postJson('/api/public/anon-salon/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            'customer' => ['phone' => '555-3333'],
        ])->assertStatus(422)->assertJsonValidationErrors('customer.name');
    }

    /**
     * Phone is the salon's key for a customer row, and two people can share one
     * (a family phone, a recycled number). A signed-in booker may not take over
     * a row another account already owns.
     */
    public function test_signed_in_booking_does_not_steal_a_row_owned_by_another_account(): void
    {
        $ctx = $this->scaffoldBookableOrg('shared-salon');

        $owner = CustomerAccount::create(['name' => 'Owner', 'email' => 'owner@x.test']);
        $existing = Customer::create([
            'organization_id' => $ctx['org']->id,
            'name' => 'Owner',
            'phone' => '555-9999',
            'email' => 'owner@x.test',
            'customer_account_id' => $owner->id,
        ]);

        $token = $this->signIn('jane@x.test');

        $this->withToken($token)->postJson('/api/public/shared-salon/book', [
            'service_id' => $ctx['service']->id,
            'staff_id' => $ctx['staff']->id,
            'date' => $this->nextMonday(),
            'start_time' => '11:00',
            'customer' => ['name' => 'Jane', 'phone' => '555-9999'],
        ])->assertCreated();

        $this->assertSame($owner->id, $existing->fresh()->customer_account_id);

        // Jane gets her own row on that phone, and her booking still reaches
        // her dashboard rather than disappearing into the other account's.
        $jane = CustomerAccount::where('email', 'jane@x.test')->first();
        $mine = Customer::where('phone', '555-9999')->where('customer_account_id', $jane->id)->first();
        $this->assertNotNull($mine);
        $this->assertNotSame($existing->id, $mine->id);

        $this->withToken($token)->getJson('/api/customer/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data.upcoming');
    }
}
