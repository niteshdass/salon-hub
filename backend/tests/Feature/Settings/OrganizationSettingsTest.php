<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The salon's own profile: what the public site shows and what the
 * reminders sign off as. Owner-only, like every other org-level setting.
 */
class OrganizationSettingsTest extends TestCase
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
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $roles = [];
        foreach (['owner', 'manager', 'staff'] as $role) {
            $user = User::create([
                'organization_id' => $org->id,
                'name' => ucfirst($role),
                'email' => "{$role}@{$slug}.test",
                'password' => 'secret1234',
                'role' => $role,
                'status' => 'active',
            ]);
            $roles["{$role}Token"] = $user->createToken('api')->plainTextToken;
        }

        return ['org' => $org] + $roles;
    }

    private function actingAsRole(array $s, string $role): static
    {
        // Sanctum memoizes the resolved user for the whole test app.
        $this->app['auth']->forgetGuards();

        return $this->withToken($s["{$role}Token"]);
    }

    public function test_the_owner_reads_the_salon_profile(): void
    {
        $s = $this->scaffold();
        Setting::create([
            'organization_id' => $s['org']->id,
            'theme_color' => '#ff0055',
            'about' => 'Best cuts in town.',
            'instagram' => 'https://instagram.com/alpha',
        ]);

        $response = $this->actingAsRole($s, 'owner')->getJson('/api/settings/organization');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Alpha');
        $response->assertJsonPath('data.email', 'owner@alpha.test');
        $response->assertJsonPath('data.theme_color', '#ff0055');
        $response->assertJsonPath('data.about', 'Best cuts in town.');
        $response->assertJsonPath('data.instagram', 'https://instagram.com/alpha');
        $response->assertJsonPath('data.logo_url', null);
        $response->assertJsonPath('data.cover_image_url', null);
    }

    public function test_reading_works_without_a_settings_row(): void
    {
        $s = $this->scaffold();

        $response = $this->actingAsRole($s, 'owner')->getJson('/api/settings/organization');

        $response->assertOk();
        // Defaults stand in until the owner saves for the first time.
        $response->assertJsonPath('data.theme_color', '#6366f1');
        $response->assertJsonPath('data.about', null);
    }

    public function test_only_the_owner_may_read_or_write(): void
    {
        $s = $this->scaffold();

        foreach (['manager', 'staff'] as $role) {
            $this->actingAsRole($s, $role)->getJson('/api/settings/organization')->assertForbidden();
            $this->actingAsRole($s, $role)
                ->putJson('/api/settings/organization', ['name' => 'Hijacked'])
                ->assertForbidden();
        }

        $this->assertSame('Alpha', $s['org']->fresh()->name);
    }

    public function test_the_owner_updates_both_the_organization_and_its_settings(): void
    {
        $s = $this->scaffold();

        $response = $this->actingAsRole($s, 'owner')->putJson('/api/settings/organization', [
            'name' => 'Alpha Hair Studio',
            'email' => 'hello@alpha.test',
            'phone' => '+1 555 0111',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'currency' => 'EUR',
            'theme_color' => '#123abc',
            'about' => 'Twenty years of sharp scissors.',
            'facebook' => 'https://facebook.com/alpha',
            'instagram' => 'https://instagram.com/alpha',
            'website' => 'https://alpha.test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Alpha Hair Studio');
        $response->assertJsonPath('data.theme_color', '#123abc');

        $org = $s['org']->fresh();
        $this->assertSame('Alpha Hair Studio', $org->name);
        $this->assertSame('America/New_York', $org->timezone);
        $this->assertSame('EUR', $org->currency);

        $settings = Setting::withoutGlobalScopes()->where('organization_id', $org->id)->first();
        $this->assertNotNull($settings);
        $this->assertSame('Twenty years of sharp scissors.', $settings->about);
        $this->assertSame('https://alpha.test', $settings->website);
    }

    public function test_a_branding_only_save_does_not_have_to_resend_the_salon_name(): void
    {
        $s = $this->scaffold();

        // The onboarding "Make it yours" step has no name field on it — it
        // sends the branding it owns and nothing else. Requiring 'name'
        // unconditionally made that save fail with "The name field is
        // required." about a field the owner could not see.
        $response = $this->actingAsRole($s, 'owner')->putJson('/api/settings/organization', [
            'theme_color' => '#be123c',
            'about' => 'We have cut hair on this street since 1998.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.theme_color', '#be123c');

        // The omitted name must be left alone, not blanked.
        $this->assertSame('Alpha', $s['org']->fresh()->name);
    }

    public function test_the_slug_cannot_be_changed_through_this_endpoint(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'owner')->putJson('/api/settings/organization', [
            'name' => 'Alpha Hair Studio',
            'slug' => 'somebody-elses-slug',
        ])->assertOk();

        // The slug is the public booking URL and the tenant key — renaming
        // it here would silently break every link that exists.
        $this->assertSame('alpha', $s['org']->fresh()->slug);
    }

    public function test_it_validates_the_profile(): void
    {
        $s = $this->scaffold();

        $this->actingAsRole($s, 'owner')->putJson('/api/settings/organization', [
            'name' => '',
            'email' => 'not-an-email',
            'currency' => 'DOLLARS',
            'theme_color' => 'blue',
            'website' => 'not-a-url',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'currency', 'theme_color', 'website']);
    }

    public function test_an_over_length_about_gets_a_plain_language_message_with_no_raw_field_key(): void
    {
        $s = $this->scaffold();

        $response = $this->actingAsRole($s, 'owner')->putJson('/api/settings/organization', [
            'name' => 'Alpha',
            'about' => str_repeat('a', 5001),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('about');

        $message = $response->json('errors.about.0');
        $this->assertSame('Your salon story must be 5000 characters or fewer.', $message);
        // Laravel's default message for this rule renders the raw attribute
        // key verbatim ("The about field must not be greater than 5000
        // characters."), which this project forbids without exception.
        $this->assertStringNotContainsString('about field', $message);
    }

    public function test_the_owner_uploads_and_replaces_a_logo(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        $first = $this->actingAsRole($s, 'owner')->post('/api/settings/organization/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

        $first->assertOk();
        $firstPath = $s['org']->fresh()->logo;
        $this->assertNotNull($firstPath);
        Storage::disk('public')->assertExists($firstPath);
        $this->assertStringContainsString($firstPath, $first->json('data.logo_url'));

        $this->actingAsRole($s, 'owner')->post('/api/settings/organization/logo', [
            'image' => UploadedFile::fake()->image('newer.png', 200, 200),
        ])->assertOk();

        // The old file is dropped rather than left to rot in storage.
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($s['org']->fresh()->logo);
    }

    public function test_the_owner_uploads_a_cover_image(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        $response = $this->actingAsRole($s, 'owner')->post('/api/settings/organization/cover', [
            'image' => UploadedFile::fake()->image('cover.jpg', 1200, 400),
        ]);

        $response->assertOk();
        Storage::disk('public')->assertExists($s['org']->fresh()->cover_image);
        $this->assertNotNull($response->json('data.cover_image_url'));
    }

    public function test_the_owner_removes_a_logo(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        $this->actingAsRole($s, 'owner')->post('/api/settings/organization/logo', [
            'image' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();
        $path = $s['org']->fresh()->logo;

        $this->actingAsRole($s, 'owner')
            ->deleteJson('/api/settings/organization/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($s['org']->fresh()->logo);
    }

    public function test_uploads_must_be_images(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        $this->actingAsRole($s, 'owner')->post('/api/settings/organization/logo', [
            'image' => UploadedFile::fake()->create('prices.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_staff_cannot_upload(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        $this->actingAsRole($s, 'staff')->post('/api/settings/organization/logo', [
            'image' => UploadedFile::fake()->image('logo.png'),
        ])->assertForbidden();

        $this->assertNull($s['org']->fresh()->logo);
    }
}
