<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\ReminderSetting;
use App\Reminders\ReminderChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one pre-appointment reminder to the customer over the org's
 * configured channel. The claim (reminder_sent_at) already happened when the
 * service dispatched this job, so delivery is best-effort: a failure is logged
 * and swallowed rather than retried.
 */
class SendAppointmentReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $appointmentId)
    {
    }

    public function handle(ReminderChannelManager $channels): void
    {
        $appointment = Appointment::with(['customer', 'service', 'organization'])
            ->find($this->appointmentId);

        $phone = $appointment?->customer?->phone;
        if (! $appointment || ! $phone) {
            return;
        }

        $settings = ReminderSetting::where('organization_id', $appointment->organization_id)->first();
        if (! $settings || ! $settings->enabled) {
            return;
        }

        try {
            $channels->for($settings->channel)->send($phone, $this->buildMessage($appointment));
        } catch (Throwable $e) {
            Log::error('Appointment reminder send failed', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildMessage(Appointment $appointment): string
    {
        $tz = $appointment->organization?->timezone ?: 'UTC';
        $startsAt = Carbon::parse(
            $appointment->booking_date->toDateString().' '.$appointment->start_time,
            $tz,
        );
        $salon = $appointment->organization?->name ?? 'the salon';
        $service = $appointment->service?->name ?? 'your appointment';

        return sprintf(
            'Reminder: %s at %s on %s, %s. See you soon!',
            $service,
            $salon,
            $startsAt->format('l, F j'),
            $startsAt->format('g:i A'),
        );
    }
}
