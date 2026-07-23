<?php

namespace Tests\Feature\Reminders;

use App\Models\Organization;
use App\Models\ReminderSetting;
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
}
