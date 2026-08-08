<?php

namespace Tests\Feature\Booking;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentServiceLineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{org: Organization, appointment: Appointment, service: Service} */
    private function scaffold(): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 40, 'status' => 'active',
        ]);
        $appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            // Still required and still cascading until Task 8 drops it.
            'service_id' => $service->id,
            'booking_date' => '2026-09-01', 'start_time' => '10:00:00',
            'end_time' => '10:30:00', 'price' => 40, 'status' => 'pending',
        ]);

        return ['org' => $org, 'appointment' => $appointment, 'service' => $service];
    }

    public function test_lines_come_back_in_sort_order(): void
    {
        ['appointment' => $appointment, 'service' => $service] = $this->scaffold();

        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $service->id,
            'name' => 'Blow Dry', 'price' => 15, 'duration' => 20, 'sort_order' => 1,
        ]);
        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $service->id,
            'name' => 'Haircut', 'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);

        $names = $appointment->fresh()->lines->pluck('name')->all();

        $this->assertSame(['Haircut', 'Blow Dry'], $names);
    }

    public function test_a_line_survives_its_service_being_removed(): void
    {
        ['org' => $org, 'appointment' => $appointment] = $this->scaffold();

        // A service the appointment's own legacy service_id does not point at,
        // so this deletion exercises the line's nullOnDelete rather than the
        // cascade still hanging off appointments.service_id until Task 8.
        $extra = Service::create([
            'organization_id' => $org->id, 'name' => 'Blow Dry',
            'duration' => 20, 'price' => 15, 'status' => 'active',
        ]);

        $line = AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $extra->id,
            'name' => 'Blow Dry', 'price' => 15, 'duration' => 20, 'sort_order' => 1,
        ]);

        // Bypasses ServiceController's refusal on purpose: the column's own
        // nullOnDelete is what guarantees history survives any future path.
        DB::table('services')->where('id', $extra->id)->delete();

        $line->refresh();

        $this->assertNull($line->service_id);
        $this->assertSame('Blow Dry', $line->name);
        $this->assertSame('15.00', $line->price);
    }

    public function test_deleting_the_appointment_takes_its_lines(): void
    {
        ['appointment' => $appointment, 'service' => $service] = $this->scaffold();

        AppointmentService::create([
            'appointment_id' => $appointment->id, 'service_id' => $service->id,
            'name' => 'Haircut', 'price' => 40, 'duration' => 30, 'sort_order' => 0,
        ]);

        $appointment->delete();

        $this->assertDatabaseCount('appointment_services', 0);
    }
}
