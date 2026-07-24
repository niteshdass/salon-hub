<?php

namespace App\Reminders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Delivery over Twilio's Messages API. SMS and WhatsApp are the same
 * request to the same account — WhatsApp only prefixes the addresses.
 *
 * Spoken to over HTTP rather than through twilio/sdk: one form POST is the
 * whole integration, and Http::fake() keeps the tests honest without a
 * client to stub.
 */
class TwilioReminderChannel implements ReminderChannel
{
    private const BASE = 'https://api.twilio.com/2010-04-01';

    public function __construct(
        protected string $accountSid,
        protected string $authToken,
        protected ?string $from = null,
        protected ?string $messagingServiceSid = null,
        protected bool $whatsapp = false,
    ) {
    }

    public function send(string $to, string $message): void
    {
        $payload = [
            'To' => $this->address($to),
            'Body' => $message,
        ];

        // A Messaging Service picks the sender itself; passing both is an
        // error on Twilio's side, so the service wins when configured.
        if ($this->messagingServiceSid) {
            $payload['MessagingServiceSid'] = $this->messagingServiceSid;
        } else {
            $payload['From'] = $this->address((string) $this->from);
        }

        Http::asForm()
            ->withBasicAuth($this->accountSid, $this->authToken)
            ->post(self::BASE."/Accounts/{$this->accountSid}/Messages.json", $payload)
            // Let a rejection surface: the job logs it, the reminder is not
            // retried, and a silent success would be worse than either.
            ->throw();
    }

    /**
     * WhatsApp addresses carry a scheme; numbers that already have one are
     * left as the salon typed them.
     */
    protected function address(string $number): string
    {
        if (! $this->whatsapp || Str::startsWith($number, 'whatsapp:')) {
            return $number;
        }

        return 'whatsapp:'.$number;
    }
}
