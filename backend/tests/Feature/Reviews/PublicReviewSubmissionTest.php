<?php

namespace Tests\Feature\Reviews;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicReviewSubmissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an active org + a single appointment in the given status, with a
     * known public token. Returns the org slug, token, and appointment.
     *
     * @return array{slug: string, token: string, appointment: Appointment}
     */
    private function scaffoldAppointment(string $slug, string $status): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create([
            'organization_id' => $org->id,
            'name' => 'Haircut',
            'duration' => 30,
            'price' => 25,
            'status' => 'active',
        ]);
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Jamie Stylist',
            'email' => "stylist@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Sam Client']);

        $appointment = Appointment::create([
            'organization_id' => $org->id,
            'public_token' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'booking_date' => '2026-08-03',
            'start_time' => '12:00:00',
            'end_time' => '12:30:00',
            'price' => 25,
            'status' => $status,
        ]);

        return ['slug' => $slug, 'token' => $appointment->public_token, 'appointment' => $appointment];
    }

    public function test_customer_can_review_a_completed_appointment(): void
    {
        $ctx = $this->scaffoldAppointment('alpha', 'completed');

        $res = $this->postJson("/api/public/alpha/manage/{$ctx['token']}/review", [
            'rating' => 5,
            'comment' => 'Fantastic cut!',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.rating', 5);
        $res->assertJsonPath('data.comment', 'Fantastic cut!');

        $this->assertDatabaseHas('reviews', [
            'appointment_id' => $ctx['appointment']->id,
            'organization_id' => $ctx['appointment']->organization_id,
            'staff_id' => $ctx['appointment']->staff_id,
            'rating' => 5,
            'reviewer_name' => 'Sam Client',
            'status' => 'published',
        ]);
    }

    public function test_cannot_review_an_appointment_that_is_not_completed(): void
    {
        $ctx = $this->scaffoldAppointment('bravo', 'confirmed');

        $this->postJson("/api/public/bravo/manage/{$ctx['token']}/review", [
            'rating' => 4,
        ])->assertStatus(422);

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_cannot_review_the_same_appointment_twice(): void
    {
        $ctx = $this->scaffoldAppointment('charlie', 'completed');

        $this->postJson("/api/public/charlie/manage/{$ctx['token']}/review", [
            'rating' => 5,
        ])->assertCreated();

        $this->postJson("/api/public/charlie/manage/{$ctx['token']}/review", [
            'rating' => 1,
        ])->assertStatus(409);

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_rating_is_required_and_bounded(): void
    {
        $ctx = $this->scaffoldAppointment('delta', 'completed');

        $this->postJson("/api/public/delta/manage/{$ctx['token']}/review", [
            'rating' => 6,
        ])->assertStatus(422)->assertJsonValidationErrors('rating');

        $this->postJson("/api/public/delta/manage/{$ctx['token']}/review", [
            'comment' => 'no rating',
        ])->assertStatus(422)->assertJsonValidationErrors('rating');
    }

    public function test_manage_payload_exposes_can_review_before_and_review_after(): void
    {
        $ctx = $this->scaffoldAppointment('echo', 'completed');

        $before = $this->getJson("/api/public/echo/manage/{$ctx['token']}");
        $before->assertOk();
        $before->assertJsonPath('data.can_review', true);
        $before->assertJsonPath('data.review', null);

        $this->postJson("/api/public/echo/manage/{$ctx['token']}/review", [
            'rating' => 4,
            'comment' => 'Nice',
        ])->assertCreated();

        $after = $this->getJson("/api/public/echo/manage/{$ctx['token']}");
        $after->assertOk();
        $after->assertJsonPath('data.can_review', false);
        $after->assertJsonPath('data.review.rating', 4);
        $after->assertJsonPath('data.review.comment', 'Nice');
    }

    public function test_manage_payload_cannot_review_when_not_completed(): void
    {
        $ctx = $this->scaffoldAppointment('foxtrot', 'pending');

        $res = $this->getJson("/api/public/foxtrot/manage/{$ctx['token']}");
        $res->assertOk();
        $res->assertJsonPath('data.can_review', false);
        $res->assertJsonPath('data.review', null);
    }
}
