<?php

namespace Tests\Feature\Reviews;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
    }

    private function makeAppointment(Organization $org): Appointment
    {
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Sam Client']);
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
            'name' => 'Stylist',
            'email' => 'stylist@'.$org->slug.'.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        return Appointment::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => '2026-08-03',
            'start_time' => '12:00:00',
            'end_time' => '12:30:00',
            'price' => 25,
            'status' => 'completed',
        ]);
    }

    public function test_rating_is_cast_to_an_integer(): void
    {
        $org = $this->makeOrg('alpha');
        $appt = $this->makeAppointment($org);

        $review = Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $appt->id,
            'rating' => '5',
            'comment' => 'Great',
            'reviewer_name' => 'Sam Client',
        ]);

        $this->assertSame(5, $review->fresh()->rating);
        $this->assertSame('published', $review->fresh()->status);
    }

    public function test_only_one_review_per_appointment(): void
    {
        $org = $this->makeOrg('bravo');
        $appt = $this->makeAppointment($org);

        Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $appt->id,
            'rating' => 4,
            'reviewer_name' => 'Sam Client',
        ]);

        $this->expectException(QueryException::class);
        Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $appt->id,
            'rating' => 3,
            'reviewer_name' => 'Sam Client',
        ]);
    }

    public function test_reviews_are_scoped_to_the_current_tenant(): void
    {
        $orgA = $this->makeOrg('charlie');
        $orgB = $this->makeOrg('delta');
        Review::create([
            'organization_id' => $orgA->id,
            'appointment_id' => $this->makeAppointment($orgA)->id,
            'rating' => 5,
            'reviewer_name' => 'A',
        ]);
        Review::create([
            'organization_id' => $orgB->id,
            'appointment_id' => $this->makeAppointment($orgB)->id,
            'rating' => 2,
            'reviewer_name' => 'B',
        ]);

        $this->assertSame(2, Review::count());

        app(CurrentTenant::class)->set($orgA);
        $this->assertSame(1, Review::count());
        $this->assertSame($orgA->id, Review::first()->organization_id);
        app(CurrentTenant::class)->forget();
    }
}
