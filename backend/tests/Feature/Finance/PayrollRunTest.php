<?php

namespace Tests\Feature\Finance;

use App\Models\PayrollRun;

class PayrollRunTest extends FinanceTestCase
{
    public function test_owner_can_open_a_run_for_a_month(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $staff = $this->makeStaff($org, ['pay_type' => 'hybrid', 'monthly_salary' => 1000, 'commission_rate' => 10]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-15', 'price' => 500]);

        $res = $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => '2026-07-01']);

        $res->assertCreated();
        $res->assertJsonPath('data.status', 'draft');
        $res->assertJsonPath('data.period_label', 'July 2026');
        $res->assertJsonPath('data.total_amount', '1050.00');
        $res->assertJsonCount(1, 'data.lines');
        $res->assertJsonPath('data.lines.0.staff_name', 'Sam Stylist');
        $res->assertJsonPath('data.lines.0.commission_amount', '50.00');
    }

    public function test_a_mid_month_date_is_normalised_to_the_first(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => '2026-07-19'])
            ->assertCreated()
            ->assertJsonPath('data.period_month', '2026-07-01');
    }

    public function test_a_second_run_for_the_same_month_is_rejected(): void
    {
        $org = $this->makeOrg();
        $token = $this->token($this->makeUser($org, 'owner'));

        $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-07-01'])->assertCreated();
        $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-07-15'])->assertStatus(422);

        $this->assertSame(1, PayrollRun::count());
    }

    public function test_a_future_month_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => now()->addMonth()->startOfMonth()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period_month');
    }

    public function test_runs_are_listed_newest_month_first(): void
    {
        $org = $this->makeOrg();
        $token = $this->token($this->makeUser($org, 'owner'));
        $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-05-01']);
        $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-07-01']);

        $res = $this->withToken($token)->getJson('/api/payroll/runs');

        $res->assertOk();
        $res->assertJsonCount(2, 'data');
        $res->assertJsonPath('data.0.period_month', '2026-07-01');
    }

    public function test_manager_and_staff_cannot_reach_payroll(): void
    {
        $org = $this->makeOrg();
        $run = PayrollRun::create(['organization_id' => $org->id, 'period_month' => '2026-07-01']);

        foreach (['manager', 'staff'] as $role) {
            $token = $this->token($this->makeUser($org, $role));
            $this->withToken($token)->getJson('/api/payroll/runs')->assertForbidden();
            $this->withToken($token)
                ->postJson('/api/payroll/runs', ['period_month' => '2026-07-01'])
                ->assertForbidden();
            // Same-org run (not a foreign tenant id), so a 403 here proves the
            // role gate rather than a 404 from BelongsToOrganization scoping.
            $this->withToken($token)
                ->getJson("/api/payroll/runs/{$run->id}")
                ->assertForbidden();
        }
    }

    public function test_another_tenants_run_is_not_found(): void
    {
        $org = $this->makeOrg();
        $other = $this->makeOrg('other');
        $owner = $this->makeUser($org, 'owner');
        $theirs = PayrollRun::create(['organization_id' => $other->id, 'period_month' => '2026-07-01']);

        $this->withToken($this->token($owner))
            ->getJson("/api/payroll/runs/{$theirs->id}")
            ->assertNotFound();
    }
}
