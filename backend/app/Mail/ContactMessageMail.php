<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $message,
    ) {}

    public function envelope(): Envelope
    {
        // replyTo the sender so the platform team can respond directly.
        return new Envelope(
            subject: 'New Glowhub contact message',
            replyTo: [$this->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.contact.message',
            with: [
                'senderName' => $this->name,
                'senderEmail' => $this->email,
                'body' => $this->message,
            ],
        );
    }
}
