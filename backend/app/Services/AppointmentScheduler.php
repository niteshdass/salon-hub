<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\Carbon;

/**
 * Domain helpers for the booking engine: deriving an appointment's
 * end time from a service duration, and detecting scheduling conflicts
 * for a staff member.
 *
 * All queries run through the Appointment model, which is auto-scoped
 * to the current tenant by BelongsToOrganization — so conflict checks
 * never leak across organizations.
 */
class AppointmentScheduler
{
    /**
     * Statuses that free up a slot: a cancelled or no-show appointment
     * does not block a new booking in the same window.
     *
     * @var list<string>
     */
    public const BLOCKING_EXEMPT_STATUSES = [
        AppointmentStatus::CANCELLED->value,
        AppointmentStatus::NO_SHOW->value,
    ];

    /**
     * Derive the end time from a start time and a duration in minutes.
     * Accepts 'H:i' or 'H:i:s'; always returns 'H:i:s'.
     */
    public function deriveEndTime(string $start, int $durationMinutes): string
    {
        return $this->parseTime($start)->addMinutes($durationMinutes)->format('H:i:s');
    }

    /**
     * Normalize a time string ('H:i' or 'H:i:s') to 'H:i:s' for storage,
     * so start_time and end_time are always stored in the same format and
     * compare consistently in conflict queries.
     */
    public function normalizeTime(string $time): string
    {
        return $this->parseTime($time)->format('H:i:s');
    }

    /**
     * Whether the given staff member already has an appointment that
     * overlaps [start, end) on the given date.
     *
     * Overlap = existing.start_time < new.end_time
     *           AND existing.end_time > new.start_time.
     *
     * Cancelled / no-show appointments are ignored. Pass $ignoreId to
     * exclude an appointment from the check (e.g. when rescheduling it).
     */
    public function hasConflict(
        int $staffId,
        string $date,
        string $start,
        string $end,
        ?int $ignoreId = null,
    ): bool {
        $startTime = $this->parseTime($start)->format('H:i:s');
        $endTime = $this->parseTime($end)->format('H:i:s');

        return Appointment::query()
            ->where('staff_id', $staffId)
            ->whereDate('booking_date', $date)
            ->whereNotIn('status', self::BLOCKING_EXEMPT_STATUSES)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();
    }

    /**
     * Normalize a time string ('H:i' or 'H:i:s') to a Carbon instance.
     */
    protected function parseTime(string $time): Carbon
    {
        $format = strlen($time) > 5 ? 'H:i:s' : 'H:i';

        return Carbon::createFromFormat($format, $time);
    }
}
