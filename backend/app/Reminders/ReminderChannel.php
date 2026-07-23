<?php

namespace App\Reminders;

/**
 * A delivery channel for a pre-appointment reminder. Implementations send a
 * single plain-text message to one recipient address (phone number).
 */
interface ReminderChannel
{
    public function send(string $to, string $message): void;
}
