<?php

namespace Tests\Feature\Booking;

use App\Actions\AppointmentServiceWriter;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentServiceWriterTest extends TestCase
{
    use RefreshDatabase;

    private Appointment $appointment;

    /** @var array<string, Service> */
    private array $services = [];

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Acme', 'slug' => 'acme',
            'email' => 'owner@acme.test', 'subscription_plan' => 'free', 'status' => 'active',
        ]);
        app(CurrentTenant::class)->set($org);

        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist', 'email' => 'stylist@acme.test',
            'password' => 'secret1234', 'role' => 'staff', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey', 'phone' => '+15550100',
        ]);

        $this->services['cut'] = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 40, 'status' => 'active',
        ]);
        $this->services['dry'] = Service::create([
            'organization_id' => $org->id, 'name' => 'Blow Dry',
            'duration' => 20, 'price' => 15, 'status' => 'active',
        ]);

        $this->appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $staff->id,
            // Still required until Task 8 drops it; the writer ignores it.
            'service_id' => $this->services['cut']->id,
            'booking_date' => '2026-09-01', 'start_time' => '10:00:00',
            'end_time' => '10:00:00', 'price' => 0, 'status' => 'pending',
        ]);
    }

    public function test_sync_writes_lines_and_recomputes_the_total_and_end_time(): void
    {
        app(AppointmentServiceWriter::class)->sync($this->appointment, [
            $this->services['cut']->id,
            $this->services['dry']->id,
        ]);

        $fresh = $this->appointment->fresh()->load('lines');

        $this->assertSame(['Haircut', 'Blow Dry'], $fresh->lines->pluck('name')->all());
        $this->assertSame([0, 1], $fresh->lines->pluck('sort_order')->all());
        $this->assertSame('55.00', $fresh->price);
        // 10:00 + 30 + 20
        $this->assertSame('10:50:00', $fresh->end_time);
    }

    public function test_sync_replaces_the_previous_lines(): void
    {
        $writer = app(AppointmentServiceWriter::class);

        $writer->sync($this->appointment, [$this->services['cut']->id, $this->services['dry']->id]);
        $writer->sync($this->appointment, [$this->services['dry']->id]);

        $fresh = $this->appointment->fresh()->load('lines');

        $this->assertCount(1, $fresh->lines);
        $this->assertSame('15.00', $fresh->price);
        $this->assertSame('10:20:00', $fresh->end_time);
    }

    public function test_totals_for_sums_duration_and_price(): void
    {
        $totals = app(AppointmentServiceWriter::class)->totalsFor([
            $this->services['cut']->id,
            $this->services['dry']->id,
        ]);

        $this->assertSame(50, $totals['duration']);
        $this->assertSame(55.0, $totals['price']);
    }

    public function test_totals_for_rejects_a_service_outside_the_tenant(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(AppointmentServiceWriter::class)->totalsFor([$this->services['cut']->id, 99999]);
    }
}
