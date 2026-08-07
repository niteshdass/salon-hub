<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Service;
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
}
