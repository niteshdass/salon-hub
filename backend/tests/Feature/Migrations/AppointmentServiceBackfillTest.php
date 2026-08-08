<?php

namespace Tests\Feature\Migrations;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentServiceBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_turns_each_legacy_service_id_into_exactly_one_line(): void
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

        // Written straight to the table: by the time this test runs the model
        // may already have dropped service_id from $fillable.
        $appointmentId = DB::table('appointments')->insertGetId([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            'service_id' => $service->id, 'public_token' => (string) Str::uuid(),
            'booking_date' => '2026-01-05', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
            // Deliberately not 40: the booking was quoted at a price the menu
            // has since left behind, and the backfill must preserve it.
            'price' => 35, 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('appointment_services')->delete();

        $this->runBackfill();

        $lines = DB::table('appointment_services')->where('appointment_id', $appointmentId)->get();

        $this->assertCount(1, $lines);
        $this->assertSame($service->id, (int) $lines[0]->service_id);
        $this->assertSame('Haircut', $lines[0]->name);
        // Compared as a number, not a string: these are raw query results, so
        // the model's decimal:2 cast never runs and each database engine picks
        // its own trailing zeros.
        $this->assertSame(35.0, (float) $lines[0]->price);
        $this->assertSame(30, (int) $lines[0]->duration);
        $this->assertSame(0, (int) $lines[0]->sort_order);

        // The appointment's own total is untouched.
        $this->assertSame(35.0, (float) DB::table('appointments')->find($appointmentId)->price);
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $this->test_it_turns_each_legacy_service_id_into_exactly_one_line();

        $this->runBackfill();

        $this->assertSame(1, DB::table('appointment_services')->count());
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_09_100100_backfill_appointment_services.php');
        $migration->up();
    }
}
