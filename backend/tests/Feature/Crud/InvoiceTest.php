<?php

namespace Tests\Feature\Crud;

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

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An org + owner token + one $40 booking for Casey.
     *
     * @return array<string, mixed>
     */
    private function scaffold(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Glow Bar',
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'currency' => 'GBP',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
        $owner = User::create([
            'organization_id' => $org->id, 'name' => 'Owner',
            'email' => "owner@{$slug}.test", 'password' => 'secret1234',
            'role' => 'owner', 'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Colour',
            'duration' => 60, 'price' => 40, 'status' => 'active',
        ]);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey',
            'phone' => '+15550100', 'email' => 'casey@example.test',
        ]);
        $appointment = Appointment::create([
            'organization_id' => $org->id, 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'staff_id' => $owner->id,
            'service_id' => $service->id,
            'booking_date' => Carbon::parse('2026-08-01')->toDateString(),
            'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'price' => 40, 'status' => 'completed',
        ]);

        return [
            'org' => $org,
            'token' => $owner->createToken('api')->plainTextToken,
            'appointment' => $appointment,
        ];
    }

    public function test_invoice_shows_the_line_totals_and_outstanding_balance(): void
    {
        $ctx = $this->scaffold('inv-open');
        $url = "/api/appointments/{$ctx['appointment']->id}";

        // Take a £15 deposit against the £40 booking.
        $this->withToken($ctx['token'])->postJson("{$url}/payments", ['amount' => 15, 'method' => 'cash'])
            ->assertCreated();

        $response = $this->withToken($ctx['token'])->getJson("{$url}/invoice");

        $response->assertOk();
        $response->assertJsonPath('data.number', 'INV-'.str_pad((string) $ctx['appointment']->id, 6, '0', STR_PAD_LEFT));
        $response->assertJsonPath('data.currency', 'GBP');
        $response->assertJsonPath('data.customer.name', 'Casey');
        $response->assertJsonPath('data.salon.name', 'Glow Bar');
        $response->assertJsonPath('data.line_items.0.description', 'Colour');
        $response->assertJsonPath('data.line_items.0.amount', '40.00');
        $response->assertJsonPath('data.subtotal', '40.00');
        $response->assertJsonPath('data.amount_paid', '15.00');
        $response->assertJsonPath('data.balance_due', '25.00');
        $response->assertJsonPath('data.paid_in_full', false);
        $response->assertJsonPath('data.payments.0.amount', '15.00');
    }

    public function test_invoice_is_marked_paid_in_full_once_the_balance_clears(): void
    {
        $ctx = $this->scaffold('inv-paid');
        $url = "/api/appointments/{$ctx['appointment']->id}";

        $this->withToken($ctx['token'])->postJson("{$url}/payments", ['amount' => 40, 'method' => 'card'])
            ->assertCreated();

        $response = $this->withToken($ctx['token'])->getJson("{$url}/invoice");

        $response->assertOk();
        $response->assertJsonPath('data.balance_due', '0.00');
        $response->assertJsonPath('data.paid_in_full', true);
    }

    public function test_another_tenants_invoice_is_not_reachable(): void
    {
        $mine = $this->scaffold('inv-mine');
        $theirs = $this->scaffold('inv-theirs');

        $this->withToken($mine['token'])
            ->getJson("/api/appointments/{$theirs['appointment']->id}/invoice")
            ->assertNotFound();
    }
}
