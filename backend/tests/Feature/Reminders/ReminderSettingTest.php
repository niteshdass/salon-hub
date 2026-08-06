<?php

namespace Tests\Feature\Reminders;

use App\Models\Organization;
use App\Models\ReminderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReminderSettingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'timezone' => 'UTC',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
    }

    public function test_credentials_are_encrypted_at_rest_and_decrypted_on_read(): void
    {
        $org = $this->makeOrg('alpha');

        $settings = ReminderSetting::create([
            'organization_id' => $org->id,
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 24,
            'credentials' => ['auth_token' => 'super-secret-token'],
        ]);

        // Model round-trips the array.
        $this->assertSame('super-secret-token', $settings->fresh()->credentials['auth_token']);
        $this->assertTrue($settings->fresh()->enabled);
        $this->assertSame(24, $settings->fresh()->lead_hours);

        // Raw column value is NOT the plaintext (it is encrypted).
        $raw = DB::table('reminder_settings')->where('id', $settings->id)->value('credentials');
        $this->assertStringNotContainsString('super-secret-token', $raw);
    }

    public function test_appointment_has_reminder_sent_at_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('appointments', 'reminder_sent_at')
        );
    }

    /**
     * @return array{0: Organization, 1: string}
     */
    private function orgWithToken(string $slug): array
    {
        $org = $this->makeOrg($slug);
        $owner = User::create([
            'organization_id' => $org->id,
            'name' => "{$slug} owner",
            'email' => "owner-{$slug}@{$slug}.test",
            'password' => 'secret1234',
            'role' => 'owner',
            'status' => 'active',
        ]);

        return [$org, $owner->createToken('api')->plainTextToken];
    }

    public function test_get_returns_defaults_when_no_settings_row_exists(): void
    {
        [, $token] = $this->orgWithToken('showdefaults');

        $res = $this->withToken($token)->getJson('/api/settings/reminders');

        $res->assertOk();
        $res->assertJsonPath('data.enabled', false);
        $res->assertJsonPath('data.channel', 'whatsapp');
        $res->assertJsonPath('data.lead_hours', 24);
        $res->assertJsonPath('data.has_credentials.twilio', false);
        $res->assertJsonPath('data.account_sid', null);
        $res->assertJsonPath('data.from', null);
    }

    public function test_get_reports_whether_the_platform_can_send_without_setup(): void
    {
        [, $token] = $this->orgWithToken('platformfallback');
        config(['services.twilio' => [
            'account_sid' => 'ACplatform',
            'auth_token' => 'platform-token',
            'from' => '+15550999',
        ]]);

        $this->withToken($token)->getJson('/api/settings/reminders')
            ->assertOk()
            // The salon has connected nothing, but reminders will still go out.
            ->assertJsonPath('data.has_credentials.twilio', false)
            ->assertJsonPath('data.platform_fallback', true);
    }

    public function test_put_persists_settings_and_never_returns_secret(): void
    {
        [$org, $token] = $this->orgWithToken('savecreds');

        $res = $this->withToken($token)->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 48,
            'credentials' => [
                'account_sid' => 'AC123456',
                'auth_token' => 'top-secret',
                'whatsapp_from' => '+14155238886',
            ],
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.enabled', true);
        $res->assertJsonPath('data.lead_hours', 48);
        $res->assertJsonPath('data.has_credentials.twilio', true);
        // Identifiers come back so the form is not blank on the next visit.
        $res->assertJsonPath('data.account_sid', 'AC123456');
        $res->assertJsonPath('data.whatsapp_from', '+14155238886');
        // Secret value is never present anywhere in the response body.
        $this->assertStringNotContainsString('top-secret', $res->getContent());

        $this->assertDatabaseHas('reminder_settings', [
            'organization_id' => $org->id,
            'enabled' => true,
            'lead_hours' => 48,
        ]);
    }

    public function test_put_with_blank_credential_preserves_stored_secret(): void
    {
        [$org, $token] = $this->orgWithToken('preserve');

        // First save writes the secret.
        $this->withToken($token)->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 24,
            'credentials' => ['auth_token' => 'keep-me'],
        ])->assertOk();

        // Second save re-submits with the secret field blank (masked form).
        $this->withToken($token)->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 12,
            'credentials' => ['auth_token' => ''],
        ])->assertOk();

        $stored = ReminderSetting::where('organization_id', $org->id)->first();
        $this->assertSame('keep-me', $stored->credentials['auth_token']);
        $this->assertSame(12, $stored->lead_hours);
    }

    public function test_settings_are_tenant_isolated(): void
    {
        [, $tokenA] = $this->orgWithToken('tenanta');
        [$orgB] = $this->orgWithToken('tenantb');

        // Org B already has a saved settings row, written directly with no
        // tenant bound (mirrors the sibling CRUD isolation tests). Switching
        // the authenticated tenant across two requests in one test method is
        // avoided on purpose: Sanctum's RequestGuard memoizes the resolved
        // user within a booted test app, so a second withToken() request would
        // re-see the first token's user — a test-harness artifact, not app
        // behaviour (each real request gets a fresh app). One request as A
        // proves the global scope hides B's row.
        ReminderSetting::create([
            'organization_id' => $orgB->id,
            'enabled' => true,
            'channel' => 'sms',
            'lead_hours' => 6,
        ]);

        // Tenant A sees its own defaults, never B's row.
        $this->withToken($tokenA)->getJson('/api/settings/reminders')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.channel', 'whatsapp');

        $this->assertDatabaseHas('reminder_settings', [
            'organization_id' => $orgB->id,
            'channel' => 'sms',
        ]);
    }

    public function test_put_validates_channel_and_lead_hours(): void
    {
        [, $token] = $this->orgWithToken('validate');

        $this->withToken($token)->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'carrier-pigeon',
            'lead_hours' => 999,
        ])->assertStatus(422)->assertJsonValidationErrors(['channel', 'lead_hours']);
    }
}
