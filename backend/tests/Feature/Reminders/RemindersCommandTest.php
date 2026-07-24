<?php

namespace Tests\Feature\Reminders;

use App\Services\AppointmentReminderService;
use Mockery;
use Tests\TestCase;

class RemindersCommandTest extends TestCase
{
    public function test_command_invokes_the_reminder_service(): void
    {
        $mock = Mockery::mock(AppointmentReminderService::class);
        $mock->shouldReceive('dispatchDue')->once();
        $this->app->instance(AppointmentReminderService::class, $mock);

        $this->artisan('reminders:send')->assertExitCode(0);
    }
}
