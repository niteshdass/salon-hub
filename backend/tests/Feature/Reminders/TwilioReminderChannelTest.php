<?php

namespace Tests\Feature\Reminders;

use App\Reminders\TwilioReminderChannel;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Delivery over Twilio's Messages API. Both reminder channels ride the
 * same account — WhatsApp is the same request with prefixed addresses.
 */
class TwilioReminderChannelTest extends TestCase
{
    private const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json';

    public function test_an_sms_is_posted_to_the_twilio_messages_endpoint(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);

        $channel = new TwilioReminderChannel(
            accountSid: 'AC123',
            authToken: 'secret-token',
            from: '+15550111',
        );

        $channel->send('+15550100', 'See you tomorrow');

        Http::assertSent(function (Request $request) {
            return $request->url() === self::ENDPOINT
                && $request['To'] === '+15550100'
                && $request['From'] === '+15550111'
                && $request['Body'] === 'See you tomorrow'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC123:secret-token'));
        });
    }

    public function test_whatsapp_prefixes_both_addresses(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM1'], 201)]);

        (new TwilioReminderChannel(
            accountSid: 'AC123',
            authToken: 'secret-token',
            from: '+14155238886',
            whatsapp: true,
        ))->send('+15550100', 'See you tomorrow');

        Http::assertSent(fn (Request $request) => $request['To'] === 'whatsapp:+15550100'
            && $request['From'] === 'whatsapp:+14155238886');
    }

    public function test_an_already_prefixed_from_number_is_left_alone(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM1'], 201)]);

        (new TwilioReminderChannel(
            accountSid: 'AC123',
            authToken: 'secret-token',
            from: 'whatsapp:+14155238886',
            whatsapp: true,
        ))->send('whatsapp:+15550100', 'Hi');

        Http::assertSent(fn (Request $request) => $request['To'] === 'whatsapp:+15550100'
            && $request['From'] === 'whatsapp:+14155238886');
    }

    public function test_a_messaging_service_replaces_the_from_number(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['sid' => 'SM1'], 201)]);

        (new TwilioReminderChannel(
            accountSid: 'AC123',
            authToken: 'secret-token',
            from: '+15550111',
            messagingServiceSid: 'MG999',
        ))->send('+15550100', 'Hi');

        Http::assertSent(fn (Request $request) => $request['MessagingServiceSid'] === 'MG999'
            && ! isset($request['From']));
    }

    public function test_a_rejected_message_raises_so_the_job_can_log_it(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'code' => 21211,
            'message' => "The 'To' number is not a valid phone number.",
        ], 400)]);

        $this->expectException(RequestException::class);

        (new TwilioReminderChannel(
            accountSid: 'AC123',
            authToken: 'secret-token',
            from: '+15550111',
        ))->send('nonsense', 'Hi');
    }
}
