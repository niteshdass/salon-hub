<?php

namespace App\Reminders;

use App\Models\ReminderSetting;

/**
 * Decides which account sends a salon's reminders.
 *
 * A salon may connect its own Twilio credentials; otherwise the platform's
 * account carries the message. With neither configured — local development,
 * tests, a salon that only wants to see what would be sent — the log driver
 * stands in, so the whole path runs without a bill.
 */
class ReminderChannelManager
{
    public function for(ReminderSetting $settings): ReminderChannel
    {
        $credentials = $this->credentials($settings);
        $whatsapp = $settings->channel === 'whatsapp';

        $from = $whatsapp
            ? ($credentials['whatsapp_from'] ?? $credentials['from'] ?? null)
            : ($credentials['from'] ?? null);

        $sid = $credentials['account_sid'] ?? null;
        $token = $credentials['auth_token'] ?? null;
        $messagingService = $credentials['messaging_service_sid'] ?? null;

        // Half a configuration sends nothing; the log driver at least leaves
        // a trace of what would have gone out.
        if (! $sid || ! $token || (! $from && ! $messagingService)) {
            return app(LogReminderChannel::class);
        }

        return new TwilioReminderChannel(
            accountSid: $sid,
            authToken: $token,
            from: $from,
            messagingServiceSid: $messagingService,
            whatsapp: $whatsapp,
        );
    }

    /**
     * Whose Twilio account to use. Own credentials are all-or-nothing: a
     * salon's sender number cannot be used with the platform's account, nor
     * the other way round, so the two sets are never merged.
     *
     * @return array<string, string>
     */
    protected function credentials(ReminderSetting $settings): array
    {
        $own = array_filter((array) ($settings->credentials ?? []), fn ($value) => filled($value));

        if (filled($own['account_sid'] ?? null) || filled($own['auth_token'] ?? null)) {
            return $own;
        }

        return array_filter((array) config('services.twilio', []), fn ($value) => filled($value));
    }
}
