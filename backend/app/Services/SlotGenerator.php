<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Service;
use App\Models\StaffTimeOff;
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
     * The bookable window is the intersection of the staff member's working
     * hours and (when supplied) the branch's opening hours. If either the staff
     * or the branch is closed on that weekday, there are no slots.
     *
     * Pass $excludeAppointmentId when rescheduling so the appointment being
     * moved does not block its own candidate slots.
     *
     * @return list<string>
     */
    public function generate(Service $service, User $staff, string $date, ?Branch $branch = null, ?int $excludeAppointmentId = null): array
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

        // Narrow the window to the branch's opening hours when the branch
        // defines them. A branch with no configured hours imposes no limit.
        if ($branch !== null && ! empty($branch->opening_hours_json)) {
            $key = strtolower(Carbon::parse($date)->format('D')); // mon..sun
            $branchHours = $branch->opening_hours_json[$key] ?? null;

            if (empty($branchHours)) {
                return []; // branch closed on this weekday
            }

            [$open, $close] = $branchHours;
            $branchOpen = Carbon::parse($date.' '.$open);
            $branchClose = Carbon::parse($date.' '.$close);

            $dayStart = $dayStart->greaterThan($branchOpen) ? $dayStart : $branchOpen;
            $dayEnd = $dayEnd->lessThan($branchClose) ? $dayEnd : $branchClose;
        }

        $isToday = Carbon::parse($date)->isToday();
        $now = Carbon::now();

        // One-off time off that intersects this date. A candidate is dropped
        // when its service window overlaps any of these ranges.
        $timeOff = StaffTimeOff::query()
            ->where('user_id', $staff->id)
            ->where('start_at', '<', Carbon::parse($date)->endOfDay())
            ->where('end_at', '>', Carbon::parse($date)->startOfDay())
            ->get(['start_at', 'end_at']);

        $slots = [];
        $candidate = $dayStart->copy();

        while ($candidate->copy()->addMinutes($service->duration)->lessThanOrEqualTo($dayEnd)) {
            $candidateEnd = $candidate->copy()->addMinutes($service->duration);

            $passed = $isToday && $candidate->lessThan($now);

            // Half-open overlap: a window that ends exactly when the time off
            // starts (or starts when it ends) is still bookable.
            $blockedByTimeOff = $timeOff->contains(
                fn ($off) => $candidate->lessThan($off->end_at)
                    && $candidateEnd->greaterThan($off->start_at),
            );

            if (! $passed && ! $blockedByTimeOff && ! $this->scheduler->hasConflict(
                $staff->id,
                $date,
                $candidate->format('H:i'),
                $candidateEnd->format('H:i'),
                $excludeAppointmentId,
            )) {
                $slots[] = $candidate->format('H:i');
            }

            $candidate->addMinutes(self::STEP_MINUTES);
        }

        return $slots;
    }
}
