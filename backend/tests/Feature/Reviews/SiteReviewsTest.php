<?php

namespace Tests\Feature\Reviews;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteReviewsTest extends TestCase
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

    private function makeStaff(Organization $org, string $name): User
    {
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => $name,
            'email' => Str::random(6)."@{$org->slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        StaffProfile::create([
            'user_id' => $staff->id,
            'designation' => 'Stylist',
            'working_days_json' => [1, 2, 3, 4, 5],
            'working_hours_json' => ['start' => '09:00', 'end' => '17:00'],
        ]);

        return $staff;
    }

    private function makeReview(Organization $org, User $staff, int $rating, string $status, string $reviewer, ?string $comment = 'Lovely'): Review
    {
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create([
            'organization_id' => $org->id,
            'name' => 'Haircut',
            'duration' => 30,
            'price' => 25,
            'status' => 'active',
        ]);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => $reviewer]);
        $appt = Appointment::create([
            'organization_id' => $org->id,
            'public_token' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'booking_date' => '2026-08-03',
            'start_time' => '12:00:00',
            'end_time' => '12:30:00',
            'price' => 25,
            'status' => 'completed',
        ]);

        return Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $appt->id,
            'staff_id' => $staff->id,
            'rating' => $rating,
            'comment' => $comment,
            'reviewer_name' => $reviewer,
            'status' => $status,
        ]);
    }

    public function test_site_payload_carries_aggregate_and_excludes_hidden(): void
    {
        $org = $this->makeOrg('alpha');
        $staff = $this->makeStaff($org, 'Jamie Rivera');
        $this->makeReview($org, $staff, 5, 'published', 'Sarah Miller');
        $this->makeReview($org, $staff, 4, 'published', 'Tom Baker');
        $this->makeReview($org, $staff, 1, 'hidden', 'Angry Person');

        $res = $this->getJson('/api/public/alpha/site');
        $res->assertOk();

        // Aggregate over the two published reviews: (5+4)/2 = 4.5.
        $res->assertJsonPath('data.rating.average', 4.5);
        $res->assertJsonPath('data.rating.count', 2);

        $reviews = $res->json('data.reviews');
        $this->assertCount(2, $reviews);
        // Hidden review's reviewer never appears.
        $names = collect($reviews)->pluck('name');
        $this->assertFalse($names->contains(fn ($n) => str_contains((string) $n, 'Angry')));
        // Name is shown as "First L." for privacy.
        $this->assertTrue($names->contains('Sarah M.'));
        $this->assertTrue($names->contains('Tom B.'));
    }

    public function test_site_payload_rating_is_null_when_no_reviews(): void
    {
        $this->makeOrg('bravo');

        $res = $this->getJson('/api/public/bravo/site');
        $res->assertOk();
        $res->assertJsonPath('data.rating.average', null);
        $res->assertJsonPath('data.rating.count', 0);
        $this->assertSame([], $res->json('data.reviews'));
    }

    public function test_each_team_member_carries_their_own_rating(): void
    {
        $org = $this->makeOrg('charlie');
        $alice = $this->makeStaff($org, 'Alice Wong');
        $bob = $this->makeStaff($org, 'Bob Stone');

        $this->makeReview($org, $alice, 5, 'published', 'C One');
        $this->makeReview($org, $alice, 4, 'published', 'C Two');
        $this->makeReview($org, $bob, 3, 'published', 'C Three');
        // A hidden review for Bob must not count.
        $this->makeReview($org, $bob, 1, 'hidden', 'C Four');

        $res = $this->getJson('/api/public/charlie/site');
        $res->assertOk();

        $team = collect($res->json('data.team'))->keyBy('name');
        // Cast: JSON can't tell 3.0 from 3, so compare as numbers.
        $this->assertSame(4.5, (float) $team['Alice Wong']['rating']['average']);
        $this->assertSame(2, $team['Alice Wong']['rating']['count']);
        $this->assertSame(3.0, (float) $team['Bob Stone']['rating']['average']);
        $this->assertSame(1, $team['Bob Stone']['rating']['count']);
    }
}
