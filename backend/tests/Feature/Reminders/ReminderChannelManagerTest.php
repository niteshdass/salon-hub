<?php

namespace Tests\Feature\Reminders;

use App\Reminders\ReminderChannel;
use App\Reminders\ReminderChannelManager;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReminderChannelManagerTest extends TestCase
{
    public function test_manager_resolves_a_reminder_channel_for_each_channel_value(): void
    {
        $manager = app(ReminderChannelManager::class);

        $this->assertInstanceOf(ReminderChannel::class, $manager->for('whatsapp'));
        $this->assertInstanceOf(ReminderChannel::class, $manager->for('sms'));
    }

    public function test_log_channel_writes_recipient_and_message(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('[reminder] to=+15550100 :: Hello there');

        app(ReminderChannelManager::class)->for('whatsapp')->send('+15550100', 'Hello there');
    }
}
