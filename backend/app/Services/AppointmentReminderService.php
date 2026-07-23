<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\Organization;
use App\Models\ReminderSetting;
use Illuminate\Support\Carbon;

/**
 * Finds appointments whose start falls within their org's configured lead
 * window and queues exactly one reminder each. Runs in the console/queue
 * context where no tenant is bound, so every query filters organization_id
 * explicitly.
 */
class AppointmentReminderService
{
    public function dispatchDue(): void
    {
        ReminderSetting::where('enabled', true)
            ->with('organization')
            ->get()
            ->each(fn (ReminderSetting $settings) => $this->dispatchForOrg($settings));
    }

    private function dispatchForOrg(ReminderSetting $settings): void
    {
        $org = $settings->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $tz = $org->timezone ?: 'UTC';
        $now = Carbon::now();
        $windowEnd = $now->copy()->addHours($settings->lead_hours);

        // Bound the DB scan by local calendar date. Local date is monotonic in
        // absolute time, so the lower bound needs no cushion; the upper bound
        // adds a day so an appointment whose local date runs ahead of the
        // window-end instant is still loaded. The exact (now, windowEnd] window
        // is then enforced per row below.
        $fromDate = $now->copy()->timezone($tz)->toDateString();
        $toDate = $windowEnd->copy()->timezone($tz)->addDay()->toDateString();

        Appointment::where('organization_id', $org->id)
            ->whereIn('status', [AppointmentStatus::PENDING->value, AppointmentStatus::CONFIRMED->value])
            ->whereNull('reminder_sent_at')
            ->whereBetween('booking_date', [$fromDate, $toDate])
            ->with('customer')
            ->get()
            ->each(function (Appointment $appointment) use ($tz, $now, $windowEnd) {
                if (! $appointment->customer?->phone) {
                    return;
                }

                $startsAt = Carbon::parse(
                    $appointment->booking_date->toDateString().' '.$appointment->start_time,
                    $tz,
                );

                // Only the (now, now + lead] window; skip past + too-far.
                if ($startsAt->lessThanOrEqualTo($now) || $startsAt->greaterThan($windowEnd)) {
                    return;
                }

                // Atomic claim: only the run that flips the null flag dispatches,
                // so overlapping hourly runs cannot double-send.
                $claimed = Appointment::where('id', $appointment->id)
                    ->whereNull('reminder_sent_at')
                    ->update(['reminder_sent_at' => $now]);

                if ($claimed === 1) {
                    SendAppointmentReminder::dispatch($appointment->id);
                }
            });
    }
}
