<?php

namespace App\Reminders;

/**
 * Resolves the delivery driver for an org's configured channel. This
 * iteration ships only the log driver, so every channel value ('whatsapp',
 * 'sms') resolves to it. Real drivers register here later with no change to
 * callers.
 */
class ReminderChannelManager
{
    public function for(string $channel): ReminderChannel
    {
        return app(LogReminderChannel::class);
    }
}
