<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Delivers a one-time passwordless login code. Not queued: the customer is
 * waiting on the code, so it is sent inline.
 */
class CustomerLoginCodeMail extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Glowhub login code');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.customer.login-code',
            with: ['code' => $this->code],
        );
    }
}
