<?php

namespace Tests\Feature\Reminders;

use App\Models\Organization;
use App\Models\ReminderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'credentials' => ['access_token' => 'super-secret-token'],
        ]);

        // Model round-trips the array.
        $this->assertSame('super-secret-token', $settings->fresh()->credentials['access_token']);
        $this->assertTrue($settings->fresh()->enabled);
        $this->assertSame(24, $settings->fresh()->lead_hours);

        // Raw column value is NOT the plaintext (it is encrypted).
        $raw = DB::table('reminder_settings')->where('id', $settings->id)->value('credentials');
        $this->assertStringNotContainsString('super-secret-token', $raw);
    }

    public function test_appointment_has_reminder_sent_at_column(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('appointments', 'reminder_sent_at')
        );
    }

    /**
     * @return array{0: Organization, 1: User}
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

        return [$org, $owner];
    }

    public function test_get_returns_defaults_when_no_settings_row_exists(): void
    {
        [, $owner] = $this->orgWithToken('showdefaults');

        $res = $this->actingAs($owner, 'sanctum')->getJson('/api/settings/reminders');

        $res->assertOk();
        $res->assertJsonPath('data.enabled', false);
        $res->assertJsonPath('data.channel', 'whatsapp');
        $res->assertJsonPath('data.lead_hours', 24);
        $res->assertJsonPath('data.has_credentials.whatsapp', false);
        $res->assertJsonPath('data.has_credentials.sms', false);
    }

    public function test_put_persists_settings_and_never_returns_secret(): void
    {
        [$org, $owner] = $this->orgWithToken('savecreds');

        $res = $this->actingAs($owner, 'sanctum')->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 48,
            'credentials' => [
                'phone_number_id' => '123456',
                'access_token' => 'top-secret',
                'template_name' => 'reminder_util',
            ],
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.enabled', true);
        $res->assertJsonPath('data.lead_hours', 48);
        $res->assertJsonPath('data.has_credentials.whatsapp', true);
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
        [$org, $owner] = $this->orgWithToken('preserve');

        // First save writes the secret.
        $this->actingAs($owner, 'sanctum')->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 24,
            'credentials' => ['access_token' => 'keep-me'],
        ])->assertOk();

        // Second save re-submits with the secret field blank (masked form).
        $this->actingAs($owner, 'sanctum')->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 12,
            'credentials' => ['access_token' => ''],
        ])->assertOk();

        $stored = ReminderSetting::where('organization_id', $org->id)->first();
        $this->assertSame('keep-me', $stored->credentials['access_token']);
        $this->assertSame(12, $stored->lead_hours);
    }

    public function test_settings_are_tenant_isolated(): void
    {
        [, $ownerA] = $this->orgWithToken('tenanta');
        [$orgB, $ownerB] = $this->orgWithToken('tenantb');

        $this->actingAs($ownerB, 'sanctum')->putJson('/api/settings/reminders', [
            'enabled' => true, 'channel' => 'sms', 'lead_hours' => 6,
        ])->assertOk();

        // Tenant A still sees its own defaults, not B's row.
        $this->actingAs($ownerA, 'sanctum')->getJson('/api/settings/reminders')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.channel', 'whatsapp');

        $this->assertDatabaseHas('reminder_settings', [
            'organization_id' => $orgB->id,
            'channel' => 'sms',
        ]);
    }

    public function test_put_validates_channel_and_lead_hours(): void
    {
        [, $owner] = $this->orgWithToken('validate');

        $this->actingAs($owner, 'sanctum')->putJson('/api/settings/reminders', [
            'enabled' => true,
            'channel' => 'carrier-pigeon',
            'lead_hours' => 999,
        ])->assertStatus(422)->assertJsonValidationErrors(['channel', 'lead_hours']);
    }
}
