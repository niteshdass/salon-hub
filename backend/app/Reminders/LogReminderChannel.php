<?php

namespace App\Reminders;

use Illuminate\Support\Facades\Log;

/**
 * Zero-cost reminder channel: records the recipient and message to the log.
 * The only driver shipped in this iteration; real WhatsApp / SMS drivers
 * implement the same interface later.
 */
class LogReminderChannel implements ReminderChannel
{
    public function send(string $to, string $message): void
    {
        Log::info("[reminder] to={$to} :: {$message}");
    }
}
