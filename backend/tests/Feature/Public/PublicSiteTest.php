<?php

namespace Tests\Feature\Public;

use App\Models\Branch;
use App\Models\Gallery;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Everything the salon's public page renders, in one unauthenticated
 * request: the story, the branding, the team, the gallery and where to
 * find the place.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function scaffold(string $slug = 'alpha'): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "hello@{$slug}.test",
            'phone' => '+1 555 0100',
            'currency' => 'USD',
            'logo' => "organizations/{$slug}/logo.png",
            'cover_image' => "organizations/{$slug}/cover.jpg",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $branch = Branch::create([
            'organization_id' => $org->id,
            'name' => 'Main',
            'city' => 'Metropolis',
            'address' => '1 High Street',
            'phone' => '+1 555 0000',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);

        return compact('org', 'branch');
    }

    public function test_it_returns_the_salon_story_and_branding(): void
    {
        $s = $this->scaffold();
        Setting::create([
            'organization_id' => $s['org']->id,
            'theme_color' => '#ff0055',
            'about' => 'Twenty years of sharp scissors.',
            'facebook' => 'https://facebook.com/alpha',
            'instagram' => 'https://instagram.com/alpha',
            'website' => 'https://alpha.test',
        ]);

        $response = $this->getJson('/api/public/alpha/site');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Alpha');
        $response->assertJsonPath('data.slug', 'alpha');
        $response->assertJsonPath('data.about', 'Twenty years of sharp scissors.');
        $response->assertJsonPath('data.theme_color', '#ff0055');
        $response->assertJsonPath('data.email', 'hello@alpha.test');
        $response->assertJsonPath('data.phone', '+1 555 0100');
        $response->assertJsonPath('data.currency', 'USD');
        $response->assertJsonPath('data.social.instagram', 'https://instagram.com/alpha');
        $this->assertStringContainsString('logo.png', $response->json('data.logo_url'));
        $this->assertStringContainsString('cover.jpg', $response->json('data.cover_image_url'));
    }

    public function test_a_salon_without_a_settings_row_still_renders(): void
    {
        $this->scaffold();

        $response = $this->getJson('/api/public/alpha/site');

        $response->assertOk();
        // Defaults stand in until the owner saves the profile for the first time.
        $response->assertJsonPath('data.theme_color', '#6366f1');
        $response->assertJsonPath('data.about', null);
        $response->assertJsonPath('data.social.facebook', null);
    }

    public function test_it_returns_branches_with_their_map_position_and_hours(): void
    {
        $s = $this->scaffold();
        $s['branch']->update([
            'opening_hours_json' => [
                'mon' => ['09:00', '17:00'],
                'sun' => null,
            ],
        ]);

        $response = $this->getJson('/api/public/alpha/site');

        $response->assertOk();
        $response->assertJsonPath('data.branches.0.name', 'Main');
        $response->assertJsonPath('data.branches.0.address', '1 High Street');
        $response->assertJsonPath('data.branches.0.latitude', 40.7128);
        $response->assertJsonPath('data.branches.0.longitude', -74.006);
        // Sorted Monday-first, the way the salon would print them.
        $response->assertJsonPath('data.branches.0.hours.0.weekday', 1);
        $response->assertJsonPath('data.branches.0.hours.0.open_time', '09:00');

        $sunday = collect($response->json('data.branches.0.hours'))->firstWhere('weekday', 0);
        $this->assertTrue($sunday['is_closed']);
    }

    public function test_the_team_lists_active_staff_with_their_profile(): void
    {
        $s = $this->scaffold();

        $stylist = User::create([
            'organization_id' => $s['org']->id,
            'name' => 'Ada',
            'email' => 'ada@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        StaffProfile::create([
            'user_id' => $stylist->id,
            'designation' => 'Senior Stylist',
            'bio' => 'Colour specialist.',
            'profile_image' => 'https://cdn.test/ada.jpg',
        ]);

        $response = $this->getJson('/api/public/alpha/site');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.team');
        $response->assertJsonPath('data.team.0.name', 'Ada');
        $response->assertJsonPath('data.team.0.designation', 'Senior Stylist');
        $response->assertJsonPath('data.team.0.bio', 'Colour specialist.');
        $response->assertJsonPath('data.team.0.photo_url', 'https://cdn.test/ada.jpg');
        // Nothing that belongs to the account rather than the person.
        $response->assertJsonMissingPath('data.team.0.email');
    }

    public function test_the_team_hides_inactive_staff_and_the_office(): void
    {
        $s = $this->scaffold();

        User::create([
            'organization_id' => $s['org']->id,
            'name' => 'Gone',
            'email' => 'gone@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'inactive',
        ]);
        User::create([
            'organization_id' => $s['org']->id,
            'name' => 'Owner',
            'email' => 'owner@alpha.test',
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/public/alpha/site');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.team');
    }

    public function test_the_gallery_comes_back_in_the_curated_order(): void
    {
        $s = $this->scaffold();
        Gallery::create(['organization_id' => $s['org']->id, 'image' => 'a.jpg', 'title' => 'last', 'sort_order' => 9]);
        Gallery::create(['organization_id' => $s['org']->id, 'image' => 'b.jpg', 'title' => 'first', 'sort_order' => 1]);

        $response = $this->getJson('/api/public/alpha/site');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.gallery');
        $response->assertJsonPath('data.gallery.0.title', 'first');
        $this->assertStringContainsString('b.jpg', $response->json('data.gallery.0.image_url'));
    }

    public function test_one_salons_page_never_shows_anothers_content(): void
    {
        $alpha = $this->scaffold('alpha');
        $beta = $this->scaffold('beta');

        Gallery::create(['organization_id' => $beta['org']->id, 'image' => 'b.jpg']);
        $theirs = User::create([
            'organization_id' => $beta['org']->id,
            'name' => 'Beta Stylist',
            'email' => 'stylist@beta.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);
        StaffProfile::create(['user_id' => $theirs->id, 'designation' => 'Stylist']);

        $response = $this->getJson('/api/public/alpha/site');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.gallery');
        $response->assertJsonCount(0, 'data.team');
        $response->assertJsonCount(1, 'data.branches');
        $this->assertNotNull($alpha['org']->id);
    }

    public function test_an_unknown_salon_is_a_404(): void
    {
        $this->scaffold();

        $this->getJson('/api/public/nobody/site')->assertNotFound();
    }

    public function test_site_returns_branch_opening_hours_monday_first(): void
    {
        $s = $this->scaffold(); // existing helper in this file
        $org = $s['org'];

        Branch::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->first()
            ->update([
                'opening_hours_json' => [
                    'mon' => ['09:00', '18:00'],
                    'sun' => null,
                ],
            ]);

        $hours = $this->getJson("/api/public/{$org->slug}/site")
            ->assertOk()
            ->json('data.branches.0.hours');

        // Monday opens the week, Sunday closes it — the way a salon prints it.
        $this->assertSame(1, $hours[0]['weekday']);
        $this->assertSame('09:00', $hours[0]['open_time']);
        $this->assertSame('18:00', $hours[0]['close_time']);
        $this->assertFalse($hours[0]['is_closed']);

        $sunday = collect($hours)->firstWhere('weekday', 0);
        $this->assertTrue($sunday['is_closed']);
        $this->assertNull($sunday['open_time']);
    }
}
