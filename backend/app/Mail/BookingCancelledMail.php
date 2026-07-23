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
 * Sent when a booking is cancelled. One class serves both audiences
 * ('customer' | 'salon'); the audience picks the subject and the branch
 * taken inside the shared markdown template. Queued.
 */
class BookingCancelledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Appointment $appointment, public string $audience = 'customer')
    {
    }

    public function envelope(): Envelope
    {
        $salon = $this->appointment->organization?->name ?? 'the salon';
        $customer = $this->appointment->customer?->name ?? 'A customer';

        $subject = $this->audience === 'salon'
            ? "Booking cancelled by {$customer}"
            : "Your booking at {$salon} has been cancelled";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking.cancelled',
            with: ['appointment' => $this->appointment, 'audience' => $this->audience],
        );
    }
}
