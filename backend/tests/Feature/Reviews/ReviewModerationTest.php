<?php

namespace Tests\Feature\Reviews;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Organization, 1: User, 2: string} */
    private function makeOrgWithOwner(string $slug): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$org, $owner, $owner->createToken('api')->plainTextToken];
    }

    private function makeReview(Organization $org, int $rating, string $status = 'published', string $reviewer = 'Sam'): Review
    {
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
            'email' => 'stylist-'.Str::random(6)."@{$org->slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => $reviewer]);
        $appt = Appointment::create([
            'organization_id' => $org->id,
            'public_token' => (string) Str::uuid(),
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

        AppointmentService::create([
            'appointment_id' => $appt->id, 'service_id' => $service->id,
            'name' => $service->name, 'price' => $service->price, 'duration' => $service->duration, 'sort_order' => 0,
        ]);

        return Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $appt->id,
            'staff_id' => $staff->id,
            'rating' => $rating,
            'comment' => 'A comment',
            'reviewer_name' => $reviewer,
            'status' => $status,
        ]);
    }

    public function test_owner_lists_reviews_with_average_and_count_meta(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('alpha');
        $this->makeReview($org, 5);
        $this->makeReview($org, 4);
        $this->makeReview($org, 2, 'hidden');

        $res = $this->withToken($token)->getJson('/api/reviews');
        $res->assertOk();
        $this->assertCount(3, $res->json('data'));
        // Average over all three: (5+4+2)/3 = 3.7 (1 dp).
        $res->assertJsonPath('meta.count', 3);
        $res->assertJsonPath('meta.average', 3.7);
        // Context is present on a row.
        $this->assertNotNull($res->json('data.0.reviewer_name'));
        $this->assertSame('Haircut', $res->json('data.0.service_name'));
    }

    public function test_owner_can_hide_and_unhide_a_review(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('bravo');
        $review = $this->makeReview($org, 5);

        $this->withToken($token)->patchJson("/api/reviews/{$review->id}", ['status' => 'hidden'])
            ->assertOk()
            ->assertJsonPath('data.status', 'hidden');
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'status' => 'hidden']);

        $this->withToken($token)->patchJson("/api/reviews/{$review->id}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_status_must_be_valid(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('bravo-x');
        $review = $this->makeReview($org, 5);

        $this->withToken($token)->patchJson("/api/reviews/{$review->id}", ['status' => 'banana'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_staff_cannot_moderate_reviews(): void
    {
        [$org] = $this->makeOrgWithOwner('charlie');
        $review = $this->makeReview($org, 5);
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => 'Front Desk',
            'email' => 'desk@charlie.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        $staffToken = $staff->createToken('api')->plainTextToken;

        // Staff may read...
        $this->withToken($staffToken)->getJson('/api/reviews')->assertOk();
        // ...but not moderate.
        $this->withToken($staffToken)->patchJson("/api/reviews/{$review->id}", ['status' => 'hidden'])
            ->assertForbidden();
        $this->withToken($staffToken)->deleteJson("/api/reviews/{$review->id}")
            ->assertForbidden();
    }

    public function test_owner_can_delete_a_review(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('delta');
        $review = $this->makeReview($org, 3);

        $this->withToken($token)->deleteJson("/api/reviews/{$review->id}")->assertNoContent();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_reviews_are_tenant_isolated(): void
    {
        [$org, , $token] = $this->makeOrgWithOwner('echo');
        $this->makeReview($org, 5);

        [$otherOrg] = $this->makeOrgWithOwner('echo-other');
        $foreign = $this->makeReview($otherOrg, 1);

        // List shows only this org's review.
        $res = $this->withToken($token)->getJson('/api/reviews');
        $this->assertCount(1, $res->json('data'));

        // The foreign review id is invisible -> 404 on both write paths.
        $this->withToken($token)->patchJson("/api/reviews/{$foreign->id}", ['status' => 'hidden'])
            ->assertNotFound();
        $this->withToken($token)->deleteJson("/api/reviews/{$foreign->id}")
            ->assertNotFound();
    }
}
