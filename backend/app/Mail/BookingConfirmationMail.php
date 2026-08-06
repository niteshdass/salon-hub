<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation sent to the customer after they book. Queued so a slow mail
 * transport never delays the booking response.
 */
class BookingConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Appointment $appointment) {}

    public function envelope(): Envelope
    {
        $salon = $this->appointment->organization?->name ?? 'the salon';

        return new Envelope(
            subject: "Your booking at {$salon} is confirmed",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking.customer',
            with: ['appointment' => $this->appointment],
        );
    }
}
