<?php

namespace Tests\Feature\Mail;

use App\Mail\BookingConfirmationMail;
use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The template's own render, not a Mail::fake() closure that only inspects
 * envelope/recipient. Task 5/6 stopped writing appointments.service_id and
 * the "Service" row silently went blank in real inboxes until Task 7 moved
 * every mail template onto Appointment::lines() — nothing in the suite ever
 * rendered a template body to notice. This proves the fix, and proves it
 * against a multi-line booking specifically: a template that printed only
 * the first line would still pass any single-service test.
 */
class BookingMailContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_email_lists_every_booked_service(): void
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Jamie Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100']);
        $haircut = Service::create(['organization_id' => $org->id, 'name' => 'Haircut', 'duration' => 30, 'price' => 40, 'status' => 'active']);
        $blowDry = Service::create(['organization_id' => $org->id, 'name' => 'Blow Dry', 'duration' => 20, 'price' => 15, 'status' => 'active']);

        $appointment = Appointment::create([
            'organization_id' => $org->id, 'public_token' => (string) Str::uuid(),
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'booking_date' => '2026-09-01', 'start_time' => '10:00:00', 'end_time' => '10:50:00',
            'price' => 55, 'status' => 'confirmed',
        ]);
        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $haircut->id,
            'name' => 'Haircut', 'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);
        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $blowDry->id,
            'name' => 'Blow Dry', 'price' => 15, 'duration' => 20, 'sort_order' => 1,
        ]);

        $html = (new BookingConfirmationMail($appointment->fresh()))->render();

        $this->assertStringContainsString('Haircut, Blow Dry', $html);
    }
}
