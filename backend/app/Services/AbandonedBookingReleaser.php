<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Appointment;

/**
 * Frees slots held by online-deposit bookings that were never completed. When
 * a customer opens the gateway but abandons checkout, the booking is left
 * pending with a pending gateway payment — without release it would block the
 * time forever. Manual transfers are excluded: those await the owner's review.
 *
 * Runs outside any tenant context, so the queries span every organization.
 */
class AbandonedBookingReleaser
{
    /**
     * Cancel every stale, unpaid gateway booking. Returns the number released.
     */
    public function release(): int
    {
        $ttl = (int) config('booking.gateway_pending_ttl_minutes', 30);
        $cutoff = now()->subMinutes($ttl);

        $appointments = Appointment::query()
            ->where('status', AppointmentStatus::PENDING->value)
            ->where('created_at', '<', $cutoff)
            // Has an online deposit still waiting on the gateway…
            ->whereHas('payments', fn ($q) => $q
                ->where('source', PaymentSource::GATEWAY->value)
                ->where('status', PaymentStatus::PENDING->value))
            // …and nothing on the booking has actually been captured.
            ->whereDoesntHave('payments', fn ($q) => $q
                ->where('status', PaymentStatus::VERIFIED->value))
            ->get();

        foreach ($appointments as $appointment) {
            $appointment->update(['status' => AppointmentStatus::CANCELLED->value]);
        }

        return $appointments->count();
    }
}
