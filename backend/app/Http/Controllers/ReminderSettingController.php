<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reminder\UpdateReminderSettingRequest;
use App\Models\ReminderSetting;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Tenant-scoped pre-appointment reminder configuration. The tenant is bound
 * by the `tenant` middleware, so reads auto-scope to the current org and
 * organization_id is auto-filled on create. Secret credential values are
 * never returned — only per-channel presence booleans.
 */
class ReminderSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', ReminderSetting::class);

        $settings = ReminderSetting::query()->first();

        return response()->json(['data' => $this->payload($settings)]);
    }

    public function update(UpdateReminderSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $orgId = app(CurrentTenant::class)->id();

        $settings = ReminderSetting::firstOrNew(['organization_id' => $orgId]);
        $settings->enabled = $data['enabled'];
        $settings->channel = $data['channel'];
        $settings->lead_hours = $data['lead_hours'];

        // Merge credentials: a blank/absent field keeps the stored secret, so
        // re-saving a masked form never wipes existing keys.
        $credentials = $settings->credentials ?? [];
        foreach ($data['credentials'] ?? [] as $key => $value) {
            if ($value !== null && $value !== '') {
                $credentials[$key] = $value;
            }
        }
        $settings->credentials = $credentials !== [] ? $credentials : null;
        $settings->save();

        return response()->json(['data' => $this->payload($settings->fresh())]);
    }

    /**
     * Safe public shape: config values, the Twilio identifiers the form
     * needs to render, and whether a secret is on file. The auth token
     * itself never leaves the server.
     *
     * @return array<string, mixed>
     */
    private function payload(?ReminderSetting $settings): array
    {
        $credentials = $settings?->credentials ?? [];
        $platform = array_filter((array) config('services.twilio', []), fn ($value) => filled($value));

        return [
            'enabled' => (bool) ($settings?->enabled ?? false),
            'channel' => $settings?->channel ?: 'whatsapp',
            'lead_hours' => (int) ($settings?->lead_hours ?? 24),

            // Identifiers, not secrets — the form is not blank on return.
            'account_sid' => $credentials['account_sid'] ?? null,
            'from' => $credentials['from'] ?? null,
            'whatsapp_from' => $credentials['whatsapp_from'] ?? null,
            'messaging_service_sid' => $credentials['messaging_service_sid'] ?? null,

            'has_credentials' => [
                'twilio' => filled($credentials['auth_token'] ?? null),
            ],
            // Reminders still go out without any of the above when the
            // platform account is configured.
            'platform_fallback' => filled($platform['account_sid'] ?? null)
                && filled($platform['auth_token'] ?? null),
        ];
    }
}
