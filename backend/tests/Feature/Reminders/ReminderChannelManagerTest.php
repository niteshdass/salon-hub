<?php

namespace Tests\Feature\Reminders;

use App\Models\ReminderSetting;
use App\Reminders\LogReminderChannel;
use App\Reminders\ReminderChannelManager;
use App\Reminders\TwilioReminderChannel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Which account sends a salon's reminders: its own Twilio credentials,
 * the platform's, or nobody's (the log driver, so a salon that has not
 * connected anything still exercises the whole path harmlessly).
 */
class ReminderChannelManagerTest extends TestCase
{
    private function settings(array $credentials = [], string $channel = 'sms'): ReminderSetting
    {
        return new ReminderSetting([
            'enabled' => true,
            'channel' => $channel,
            'lead_hours' => 24,
            'credentials' => $credentials ?: null,
        ]);
    }

    public function test_a_salon_with_its_own_credentials_sends_through_them(): void
    {
        Http::fake();
        config(['services.twilio' => ['account_sid' => 'ACplatform', 'auth_token' => 'platform', 'from' => '+15550999']]);

        $channel = app(ReminderChannelManager::class)->for($this->settings([
            'account_sid' => 'ACsalon',
            'auth_token' => 'salon-token',
            'from' => '+15550111',
        ]));

        $this->assertInstanceOf(TwilioReminderChannel::class, $channel);

        $channel->send('+15550100', 'Hi');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ACsalon')
            && $request['From'] === '+15550111');
    }

    public function test_a_salon_without_credentials_falls_back_to_the_platform_account(): void
    {
        Http::fake();
        config(['services.twilio' => [
            'account_sid' => 'ACplatform',
            'auth_token' => 'platform-token',
            'from' => '+15550999',
        ]]);

        $channel = app(ReminderChannelManager::class)->for($this->settings());

        $this->assertInstanceOf(TwilioReminderChannel::class, $channel);

        $channel->send('+15550100', 'Hi');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ACplatform')
            && $request['From'] === '+15550999');
    }

    public function test_the_whatsapp_channel_uses_the_whatsapp_sender(): void
    {
        Http::fake();
        config(['services.twilio' => [
            'account_sid' => 'ACplatform',
            'auth_token' => 'platform-token',
            'from' => '+15550999',
            'whatsapp_from' => '+14155238886',
        ]]);

        app(ReminderChannelManager::class)
            ->for($this->settings(channel: 'whatsapp'))
            ->send('+15550100', 'Hi');

        Http::assertSent(fn (Request $request) => $request['From'] === 'whatsapp:+14155238886'
            && $request['To'] === 'whatsapp:+15550100');
    }

    public function test_nothing_configured_anywhere_falls_back_to_the_log(): void
    {
        config(['services.twilio' => []]);

        $channel = app(ReminderChannelManager::class)->for($this->settings());

        $this->assertInstanceOf(LogReminderChannel::class, $channel);

        Log::shouldReceive('info')->once()->with('[reminder] to=+15550100 :: Hello there');
        $channel->send('+15550100', 'Hello there');
    }

    public function test_half_configured_credentials_do_not_pretend_to_send(): void
    {
        // A sid with no token would fail on every call; the log driver at
        // least leaves a trace of what would have gone out.
        config(['services.twilio' => []]);

        $channel = app(ReminderChannelManager::class)->for($this->settings([
            'account_sid' => 'ACsalon',
        ]));

        $this->assertInstanceOf(LogReminderChannel::class, $channel);
    }
}
