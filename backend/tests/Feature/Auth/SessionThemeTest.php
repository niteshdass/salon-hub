<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin UI paints itself with the salon's accent, and staff never touch
 * the owner-only settings endpoints — so the colour has to ride along on the
 * session payload every role already receives.
 */
class SessionThemeTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Alpha',
            'slug' => 'alpha',
            'email' => 'owner@alpha.test',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'name' => 'Staffer',
            'email' => 'staff@alpha.test',
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        return [$org, $user];
    }

    public function test_me_carries_the_salon_theme_colour_for_a_staff_user(): void
    {
        [$org, $user] = $this->scaffold();
        Setting::create(['organization_id' => $org->id, 'theme_color' => '#0f766e']);

        $response = $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('organization.theme_color', '#0f766e');
    }

    public function test_login_carries_the_theme_colour(): void
    {
        [$org, $user] = $this->scaffold();
        Setting::create(['organization_id' => $org->id, 'theme_color' => '#be123c']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'staff@alpha.test',
            'password' => 'secret1234',
        ]);

        $response->assertOk()
            ->assertJsonPath('organization.theme_color', '#be123c');
    }

    public function test_a_salon_without_a_settings_row_reports_a_null_theme_colour(): void
    {
        [, $user] = $this->scaffold();

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('organization.theme_color', null);
    }
}
