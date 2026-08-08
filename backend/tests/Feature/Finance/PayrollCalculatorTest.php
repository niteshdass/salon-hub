<?php

namespace Tests\Feature\Finance;

use App\Models\Organization;
use App\Services\PayrollCalculator;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

class PayrollCalculatorTest extends FinanceTestCase
{
    /**
     * The calculator relies on the tenant global scope, which HTTP requests
     * get from ResolveTenant. Bind it by hand for these direct calls.
     */
    private function calculateFor(Organization $org, string $month): array
    {
        app(CurrentTenant::class)->set($org);

        return app(PayrollCalculator::class)->linesFor(Carbon::parse($month));
    }

    public function test_commission_staff_earn_a_percentage_of_completed_revenue(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 25]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-05', 'price' => 400]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-20', 'price' => 600]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertCount(1, $lines);
        $this->assertSame(1000.0, $lines[0]['earned_revenue']);
        $this->assertSame(2, $lines[0]['bookings']);
        $this->assertSame(250.0, $lines[0]['commission_amount']);
        $this->assertSame(0.0, $lines[0]['salary_amount']);
        $this->assertSame(250.0, $lines[0]['total_amount']);
    }

    public function test_salary_staff_are_paid_regardless_of_bookings(): void
    {
        $org = $this->makeOrg();
        $this->makeStaff($org, ['pay_type' => 'salary', 'monthly_salary' => 900]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertSame(0.0, $lines[0]['earned_revenue']);
        $this->assertSame(900.0, $lines[0]['salary_amount']);
        $this->assertSame(0.0, $lines[0]['commission_amount']);
        $this->assertSame(900.0, $lines[0]['total_amount']);
    }

    public function test_hybrid_staff_get_salary_plus_commission(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeStaff($org, ['pay_type' => 'hybrid', 'monthly_salary' => 1000, 'commission_rate' => 10]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-05', 'price' => 500]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertSame(1050.0, $lines[0]['total_amount']);
    }

    public function test_staff_with_no_pay_rule_are_excluded(): void
    {
        $org = $this->makeOrg();
        $this->makeStaff($org); // pay_type 'none'

        $this->assertSame([], $this->calculateFor($org, '2026-07-01'));
    }

    public function test_only_completed_appointments_inside_the_month_count(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 50]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-10', 'price' => 100]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-11', 'price' => 100, 'status' => 'cancelled']);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-12', 'price' => 100, 'status' => 'pending']);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-08-01', 'price' => 100]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-06-30', 'price' => 100]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertSame(100.0, $lines[0]['earned_revenue']);
        $this->assertSame(50.0, $lines[0]['commission_amount']);
    }

    public function test_commission_rounds_to_two_decimals(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 33.33]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-10', 'price' => 1000.01]);

        $lines = $this->calculateFor($org, '2026-07-01');

        // 1000.01 * 0.3333 = 333.303333 -> 333.30
        $this->assertSame(333.3, $lines[0]['commission_amount']);
    }

    public function test_another_tenants_revenue_never_leaks_into_a_line(): void
    {
        $org = $this->makeOrg();
        $other = $this->makeOrg('other');
        $mine = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 100]);
        $theirs = $this->makeStaff($other, ['pay_type' => 'commission', 'commission_rate' => 100]);
        $this->makeAppointment($org, ['staff' => $mine, 'date' => '2026-07-10', 'price' => 100]);
        $this->makeAppointment($other, ['staff' => $theirs, 'date' => '2026-07-10', 'price' => 900]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertCount(1, $lines);
        $this->assertSame(100.0, $lines[0]['earned_revenue']);
    }
}
