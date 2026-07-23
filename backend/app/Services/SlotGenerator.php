<?php

namespace App\Services;

use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;

/**
 * Generates the open (bookable) start times for a staff member on a given
 * date, given the duration of the service being booked.
 *
 * A slot is a 30-minute grid position at which the service can start and
 * finish within the staff member's working hours, does not collide with an
 * existing appointment, and (for today) has not already passed.
 */
class SlotGenerator
{
    /** Interval between candidate start times, in minutes. */
    private const STEP_MINUTES = 30;

    /** Fallback working window when the staff profile does not define one. */
    private const DEFAULT_HOURS = ['start' => '09:00', 'end' => '18:00'];

    public function __construct(protected AppointmentScheduler $scheduler)
    {
    }

    /**
     * Open 'H:i' start times for the staff member on the date.
     *
     * @return list<string>
     */
    public function generate(Service $service, User $staff, string $date): array
    {
        $profile = $staff->staffProfile;
        $weekday = Carbon::parse($date)->dayOfWeekIso; // 1=Mon .. 7=Sun

        // Closed on this weekday when the profile lists working days and this
        // one is not among them. An empty/absent list means "open every day".
        $workingDays = $profile?->working_days_json ?? [];
        if (! empty($workingDays) && ! in_array($weekday, array_map('intval', $workingDays), true)) {
            return [];
        }

        $hours = $profile?->working_hours_json ?: self::DEFAULT_HOURS;
        $dayStart = Carbon::parse($date.' '.($hours['start'] ?? self::DEFAULT_HOURS['start']));
        $dayEnd = Carbon::parse($date.' '.($hours['end'] ?? self::DEFAULT_HOURS['end']));

        $isToday = Carbon::parse($date)->isToday();
        $now = Carbon::now();

        $slots = [];
        $candidate = $dayStart->copy();

        while ($candidate->copy()->addMinutes($service->duration)->lessThanOrEqualTo($dayEnd)) {
            $candidateEnd = $candidate->copy()->addMinutes($service->duration);

            $passed = $isToday && $candidate->lessThan($now);

            if (! $passed && ! $this->scheduler->hasConflict(
                $staff->id,
                $date,
                $candidate->format('H:i'),
                $candidateEnd->format('H:i'),
            )) {
                $slots[] = $candidate->format('H:i');
            }

            $candidate->addMinutes(self::STEP_MINUTES);
        }

        return $slots;
    }
}
