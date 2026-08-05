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
 * Alert to the salon that a new booking has come in. Queued alongside the
 * customer confirmation.
 */
class NewBookingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Appointment $appointment) {}

    public function envelope(): Envelope
    {
        $customer = $this->appointment->customer?->name ?? 'A customer';

        return new Envelope(
            subject: "New booking from {$customer}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking.salon',
            with: ['appointment' => $this->appointment],
        );
    }
}
