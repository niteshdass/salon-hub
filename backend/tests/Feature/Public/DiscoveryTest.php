<?php

namespace Tests\Feature\Public;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-tenant salon discovery: who is listed, what a card shows, what the
 * search box matches, and in what order results arrive.
 */
class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A salon that can take a booking: active, onboarded, one branch, one
     * active service. Every eligibility test starts from this and breaks
     * exactly one condition.
     */
    private function salon(string $slug, array $overrides = []): Organization
    {
        $org = Organization::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'email' => "hello@{$slug}.test",
            'phone' => '+880 1700 000000',
            'currency' => 'BDT',
            'subscription_plan' => 'free',
            'status' => 'active',
            'onboarding_completed_at' => now(),
        ], $overrides));

        Branch::create([
            'organization_id' => $org->id,
            'name' => 'Main',
            'city' => 'Sylhet',
            'address' => '1 Zindabazar',
        ]);

        Service::create([
            'organization_id' => $org->id,
            'name' => 'Hair cut',
            'duration' => 30,
            'price' => 500,
            'status' => 'active',
        ]);

        return $org;
    }

    public function test_it_lists_a_salon_that_can_take_a_booking(): void
    {
        $this->salon('chastity-hyde');

        $response = $this->getJson('/api/discover/salons');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'chastity-hyde');
        $response->assertJsonPath('data.0.name', 'Chastity hyde');
        $response->assertJsonPath('data.0.city', 'Sylhet');
        $response->assertJsonPath('data.0.currency', 'BDT');
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.page', 1);
        $response->assertJsonPath('meta.per_page', 12);
    }

    /**
     * The endpoint runs with no tenant bound, so the BelongsToOrganization
     * scope can't protect it — the hand-built ->map() in the controller is
     * the only thing standing between this query and a cross-tenant leak.
     * assertSame on the exact key set (not just the values) means a stray
     * key added to that map — organizations.email, say — fails this test
     * even though nothing here asserts what such a key would contain.
     */
    public function test_a_card_exposes_exactly_the_documented_fields(): void
    {
        $this->salon('chastity-hyde');

        $response = $this->getJson('/api/discover/salons');

        $this->assertSame(
            ['slug', 'name', 'city', 'cover_image_url', 'logo_url', 'currency', 'price_from', 'rating', 'services'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_it_hides_a_suspended_salon(): void
    {
        $this->salon('open-one');
        $this->salon('closed-one', ['status' => 'suspended']);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'open-one');
    }

    public function test_it_hides_a_salon_that_never_finished_onboarding(): void
    {
        $this->salon('open-one');
        $this->salon('half-done', ['onboarding_completed_at' => null]);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'open-one');
    }

    public function test_it_hides_a_salon_with_no_branch(): void
    {
        $this->salon('open-one');
        $nowhere = $this->salon('nowhere');
        Branch::withoutGlobalScopes()->where('organization_id', $nowhere->id)->delete();

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'open-one');
    }

    public function test_it_hides_a_salon_whose_only_service_is_inactive(): void
    {
        $this->salon('open-one');
        $quiet = $this->salon('nothing-to-book');
        Service::withoutGlobalScopes()
            ->where('organization_id', $quiet->id)
            ->update(['status' => 'inactive']);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'open-one');
    }

    public function test_it_pages_results_without_repeating_a_salon(): void
    {
        foreach (range(1, 14) as $n) {
            $this->salon('salon-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }

        $first = $this->getJson('/api/discover/salons')->assertOk();
        $second = $this->getJson('/api/discover/salons?page=2')->assertOk();

        $this->assertCount(12, $first->json('data'));
        $this->assertCount(2, $second->json('data'));
        $this->assertSame(14, $first->json('meta.total'));
        $this->assertSame(2, $second->json('meta.page'));

        $slugs = array_merge(
            array_column($first->json('data'), 'slug'),
            array_column($second->json('data'), 'slug'),
        );
        $this->assertSame($slugs, array_unique($slugs));
    }

    public function test_a_card_shows_the_cheapest_active_service_and_a_service_preview(): void
    {
        $org = $this->salon('chastity-hyde');
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Hair spa',
            'duration' => 60,
            'price' => 1200,
            'status' => 'active',
        ]);
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Bridal package',
            'duration' => 180,
            'price' => 100,
            'status' => 'inactive',
        ]);
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Facial',
            'duration' => 45,
            'price' => 900,
            'status' => 'active',
        ]);
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Manicure',
            'duration' => 30,
            'price' => 700,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/discover/salons');

        // 100 belongs to the inactive service and must not be advertised.
        $this->assertSame('500.00', $response->json('data.0.price_from'));
        $this->assertSame(
            ['Hair cut', 'Hair spa', 'Facial'],
            $response->json('data.0.services'),
        );
    }

    public function test_a_salon_with_two_published_reviews_shows_no_rating(): void
    {
        $org = $this->salon('chastity-hyde');
        $this->review($org, 5);
        $this->review($org, 5);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonCount(1, 'data');
        $this->assertNull($response->json('data.0.rating'));
    }

    public function test_a_salon_with_three_published_reviews_shows_its_rating(): void
    {
        $org = $this->salon('chastity-hyde');
        $this->review($org, 5);
        $this->review($org, 4);
        $this->review($org, 4);
        $this->review($org, 1, 'hidden');

        $response = $this->getJson('/api/discover/salons');

        // The hidden review counts for neither the average nor the count.
        $response->assertJsonPath('data.0.rating.average', 4.3);
        $response->assertJsonPath('data.0.rating.count', 3);
    }

    public function test_it_matches_a_salon_by_name_case_insensitively(): void
    {
        $this->salon('chastity-hyde');
        $this->salon('heaven-touch');

        $response = $this->getJson('/api/discover/salons?q=CHASTITY');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'chastity-hyde');
    }

    public function test_it_matches_a_salon_by_slug(): void
    {
        $this->salon('chastity-hyde');
        $this->salon('heaven-touch');

        $response = $this->getJson('/api/discover/salons?q=heaven-touch');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'heaven-touch');
    }

    public function test_it_matches_a_salon_by_city(): void
    {
        $this->salon('chastity-hyde');
        $dhaka = $this->salon('heaven-touch');
        Branch::withoutGlobalScopes()
            ->where('organization_id', $dhaka->id)
            ->update(['city' => 'Dhaka']);

        $response = $this->getJson('/api/discover/salons?q=dhaka');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'heaven-touch');
    }

    public function test_it_matches_a_salon_by_service_name(): void
    {
        $this->salon('chastity-hyde');
        $spa = $this->salon('heaven-touch');
        Service::create([
            'organization_id' => $spa->id,
            'name' => 'Hot stone massage',
            'duration' => 60,
            'price' => 2000,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/discover/salons?q=massage');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'heaven-touch');
    }

    public function test_it_never_matches_an_inactive_service(): void
    {
        $org = $this->salon('chastity-hyde');
        Service::create([
            'organization_id' => $org->id,
            'name' => 'Hot stone massage',
            'duration' => 60,
            'price' => 2000,
            'status' => 'inactive',
        ]);

        $response = $this->getJson('/api/discover/salons?q=massage');

        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_an_empty_query_browses_every_listed_salon(): void
    {
        $this->salon('chastity-hyde');
        $this->salon('heaven-touch');

        // Raw spaces in a URI are rejected outright by Symfony's request
        // parser before the app ever sees them, so the whitespace-only
        // query has to travel percent-encoded, as a browser would send it.
        $response = $this->getJson('/api/discover/salons?q=%20%20%20');

        $response->assertJsonCount(2, 'data');
    }

    public function test_a_name_match_outranks_a_service_only_match(): void
    {
        // "Zenith" is named for what the other salon merely sells, and sorts
        // last alphabetically — so only ranking can put it first.
        $this->salon('zenith-massage');
        $other = $this->salon('aabode-spa');
        Service::create([
            'organization_id' => $other->id,
            'name' => 'Hot stone massage',
            'duration' => 60,
            'price' => 2000,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/discover/salons?q=massage');

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.slug', 'zenith-massage');
        $response->assertJsonPath('data.1.slug', 'aabode-spa');
    }

    public function test_a_salon_taking_bookings_outranks_a_quiet_one(): void
    {
        // Alphabetical order would put "aabode" first; recent activity must not.
        $this->salon('aabode-spa');
        $busy = $this->salon('zenith-massage');
        $this->booking($busy);

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonPath('data.0.slug', 'zenith-massage');
        $response->assertJsonPath('data.1.slug', 'aabode-spa');
    }

    public function test_equally_quiet_salons_are_ordered_by_name(): void
    {
        $this->salon('zenith-massage');
        $this->salon('aabode-spa');

        $response = $this->getJson('/api/discover/salons');

        $response->assertJsonPath('data.0.slug', 'aabode-spa');
        $response->assertJsonPath('data.1.slug', 'zenith-massage');
    }

    public function test_tied_salons_break_the_tie_by_id_so_pagination_never_repeats_or_skips(): void
    {
        // Two salons that share a name tie on name_rank (no q), on activity
        // (neither ever booked), and on name itself. Only organizations.id
        // can break that tie — without it, the pair's relative order across
        // two separate paginated queries is not guaranteed by either engine.
        foreach (range(1, 11) as $n) {
            $this->salon('filler-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }
        $first = $this->salon('same-name-first', ['name' => 'Same Salon']);
        $second = $this->salon('same-name-second', ['name' => 'Same Salon']);

        $page1 = $this->getJson('/api/discover/salons')->assertOk();
        $page2 = $this->getJson('/api/discover/salons?page=2')->assertOk();

        $this->assertCount(12, $page1->json('data'));
        $this->assertCount(1, $page2->json('data'));

        // Lower id (created first) sorts first once name is tied.
        $this->assertSame('same-name-first', $page1->json('data.11.slug'));
        $this->assertSame('same-name-second', $page2->json('data.0.slug'));

        $slugs = array_merge(
            array_column($page1->json('data'), 'slug'),
            array_column($page2->json('data'), 'slug'),
        );
        $this->assertSame($slugs, array_unique($slugs));
    }

    /**
     * A review attached to a salon. `reviews.appointment_id` is NOT NULL and
     * unique — a review only exists because someone was served — so each one
     * needs its own booking.
     */
    private function review(Organization $org, int $rating, string $status = 'published'): Review
    {
        return Review::create([
            'organization_id' => $org->id,
            'appointment_id' => $this->booking($org)->id,
            'rating' => $rating,
            'comment' => 'Lovely.',
            'reviewer_name' => 'Sadia Rahman',
            'status' => $status,
        ]);
    }

    /** One booking taken by a salon, which is what "still running" means here. */
    private function booking(Organization $org): Appointment
    {
        $branch = Branch::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();
        $service = Service::withoutGlobalScopes()->where('organization_id', $org->id)->firstOrFail();

        $staff = User::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'name' => 'Rifat',
            'email' => 'rifat-'.fake()->unique()->numerify('####').'@'.$org->slug.'.test',
            'password' => bcrypt('secret1234'),
            'role' => 'staff',
            'status' => 'active',
        ]);

        $customer = Customer::create([
            'organization_id' => $org->id,
            'name' => 'Nabila',
            'phone' => '555-'.fake()->unique()->numerify('####'),
        ]);

        return Appointment::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => now()->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
        ]);
    }
}
