<?php

namespace Tests\Feature\Reminders;

use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ReminderSetting;
use App\Models\Service;
use App\Models\User;
use App\Reminders\ReminderChannel;
use App\Reminders\ReminderChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendAppointmentReminderJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_reminder_message_over_the_resolved_channel(): void
    {
        // Record every send() the job makes through a fake channel + manager.
        $sent = [];
        $channel = new class($sent) implements ReminderChannel {
            public function __construct(public array &$sent)
            {
            }

            public function send(string $to, string $message): void
            {
                $this->sent[] = ['to' => $to, 'message' => $message];
            }
        };
        $manager = new class($channel) extends ReminderChannelManager {
            public function __construct(private ReminderChannel $channel)
            {
            }

            public function for(string $channel): ReminderChannel
            {
                return $this->channel;
            }
        };
        $this->app->instance(ReminderChannelManager::class, $manager);

        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Glow Bar',
            'slug' => 'glow-bar',
            'email' => 'owner@glow-bar.test',
            'timezone' => 'UTC',
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
        ReminderSetting::create([
            'organization_id' => $org->id,
            'enabled' => true,
            'channel' => 'whatsapp',
            'lead_hours' => 24,
        ]);
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $staff = User::create([
            'organization_id' => $org->id, 'name' => 'Stylist',
            'email' => 'stylist@glow-bar.test', 'password' => 'secret1234',
            'role' => 'staff', 'status' => 'active',
        ]);
        $service = Service::create([
            'organization_id' => $org->id, 'name' => 'Haircut',
            'duration' => 30, 'price' => 20, 'status' => 'active',
        ]);
        $customer = Customer::create([
            'organization_id' => $org->id, 'name' => 'Casey',
            'phone' => '+15550100', 'email' => 'casey@example.test',
        ]);
        $appointment = Appointment::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'booking_date' => Carbon::parse('2026-08-01')->toDateString(),
            'start_time' => '14:30:00',
            'end_time' => '15:00:00',
            'status' => 'confirmed',
        ]);

        (new SendAppointmentReminder($appointment->id))->handle($manager);

        $this->assertCount(1, $sent);
        $this->assertSame('+15550100', $sent[0]['to']);
        $this->assertStringContainsString('Haircut', $sent[0]['message']);
        $this->assertStringContainsString('Glow Bar', $sent[0]['message']);
        $this->assertStringContainsString('2:30 PM', $sent[0]['message']);
    }
}
