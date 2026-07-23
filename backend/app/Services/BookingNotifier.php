<?php

namespace App\Services;

use App\Mail\BookingConfirmationMail;
use App\Mail\NewBookingMail;
use App\Models\Appointment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the booking emails: a confirmation to the customer (when they gave an
 * email) and an alert to the salon. Mailables are queued, so this just pushes
 * jobs. Failures are logged and swallowed — a booking is already persisted and
 * must never fail because notification delivery hiccuped.
 */
class BookingNotifier
{
    public function sendForNewBooking(Appointment $appointment): void
    {
        $appointment->loadMissing(['service', 'staff', 'branch', 'customer', 'organization']);

        try {
            if ($email = $appointment->customer?->email) {
                Mail::to($email)->send(new BookingConfirmationMail($appointment));
            }

            if ($salonEmail = $appointment->organization?->email) {
                Mail::to($salonEmail)->send(new NewBookingMail($appointment));
            }
        } catch (Throwable $e) {
            Log::error('Booking notification failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
