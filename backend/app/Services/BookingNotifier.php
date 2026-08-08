<?php

namespace App\Services;

use App\Mail\BookingCancelledMail;
use App\Mail\BookingConfirmationMail;
use App\Mail\BookingRescheduledMail;
use App\Mail\NewBookingMail;
use App\Models\Appointment;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the booking lifecycle emails: a note to the customer (when they gave
 * an email) and an alert to the salon, for new / rescheduled / cancelled
 * bookings. Mailables are queued, so this just pushes jobs. Failures are
 * logged and swallowed — the booking change is already persisted and must
 * never fail because notification delivery hiccuped.
 */
class BookingNotifier
{
    public function sendForNewBooking(Appointment $appointment): void
    {
        $this->deliver(
            $appointment,
            fn () => new BookingConfirmationMail($appointment),
            fn () => new NewBookingMail($appointment),
        );
    }

    public function sendForReschedule(Appointment $appointment): void
    {
        $this->deliver(
            $appointment,
            fn () => new BookingRescheduledMail($appointment, 'customer'),
            fn () => new BookingRescheduledMail($appointment, 'salon'),
        );
    }

    public function sendForCancellation(Appointment $appointment): void
    {
        $this->deliver(
            $appointment,
            fn () => new BookingCancelledMail($appointment, 'customer'),
            fn () => new BookingCancelledMail($appointment, 'salon'),
        );
    }

    /**
     * Queue the customer mail (only when they have an email) and the salon
     * mail (only when the org has one), swallowing and logging any failure.
     * Mailables are built lazily so nothing is constructed for a missing
     * recipient.
     *
     * @param  callable(): Mailable  $customerMail
     * @param  callable(): Mailable  $salonMail
     */
    private function deliver(Appointment $appointment, callable $customerMail, callable $salonMail): void
    {
        $appointment->loadMissing(['lines', 'staff', 'branch', 'customer', 'organization']);

        try {
            if ($email = $appointment->customer?->email) {
                Mail::to($email)->send($customerMail());
            }

            if ($salonEmail = $appointment->organization?->email) {
                Mail::to($salonEmail)->send($salonMail());
            }
        } catch (Throwable $e) {
            Log::error('Booking notification failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
