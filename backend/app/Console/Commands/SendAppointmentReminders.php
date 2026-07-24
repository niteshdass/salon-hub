<?php

namespace App\Console\Commands;

use App\Services\AppointmentReminderService;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Queue due pre-appointment reminders for all organizations';

    public function handle(AppointmentReminderService $service): int
    {
        $service->dispatchDue();

        return self::SUCCESS;
    }
}
