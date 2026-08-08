<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;

class ProfitReportTest extends FinanceTestCase
{
    public function test_profit_is_earned_revenue_minus_expenses_in_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $this->makeAppointment($org, ['date' => '2026-07-10', 'price' => 500]);
        Expense::create([
            'organization_id' => $org->id, 'category' => 'rent',
            'expense_date' => '2026-07-01', 'amount' => 200,
        ]);
        Expense::create([
            'organization_id' => $org->id, 'category' => 'supplies',
            'expense_date' => '2026-07-05', 'amount' => 50,
        ]);

        $res = $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $res->assertOk();
        $res->assertJsonPath('data.profit.earned', 500);
        $res->assertJsonPath('data.profit.expenses_total', 250);
        $res->assertJsonPath('data.profit.net_profit', 250);
        $res->assertJsonPath('data.profit.expenses_by_category.0.category', 'rent');
        $res->assertJsonPath('data.profit.expenses_by_category.0.amount', 200);
        // rent is 200 of a 250 total (80%), supplies is 50 of 250 (20%).
        // Different amounts on purpose: a swapped numerator/denominator
        // (250/200 = 125%) or a wrong denominator (200/earned=500 = 40%)
        // would both land on a different number than the correct 80%.
        // json_encode collapses a whole-number float (80.0) to 80, so the
        // decoded value is an int here — matching the existing convention
        // for 'earned' etc. elsewhere in this test.
        $res->assertJsonPath('data.profit.expenses_by_category.0.share_pct', 80);
        $res->assertJsonPath('data.profit.expenses_by_category.1.category', 'supplies');
        $res->assertJsonPath('data.profit.expenses_by_category.1.amount', 50);
        $res->assertJsonPath('data.profit.expenses_by_category.1.share_pct', 20);
    }

    public function test_share_pct_rounds_to_one_decimal_place(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        // 300 total: 200/300 = 66.666...% and 100/300 = 33.333...%, neither
        // of which is exact, so this pins down the rounding rule itself.
        Expense::create([
            'organization_id' => $org->id, 'category' => 'equipment',
            'expense_date' => '2026-07-02', 'amount' => 200,
        ]);
        Expense::create([
            'organization_id' => $org->id, 'category' => 'marketing',
            'expense_date' => '2026-07-03', 'amount' => 100,
        ]);

        $res = $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $res->assertOk();
        $res->assertJsonPath('data.profit.expenses_by_category.0.category', 'equipment');
        $res->assertJsonPath('data.profit.expenses_by_category.0.share_pct', 66.7);
        $res->assertJsonPath('data.profit.expenses_by_category.1.category', 'marketing');
        $res->assertJsonPath('data.profit.expenses_by_category.1.share_pct', 33.3);
    }

    public function test_share_pct_is_zero_rather_than_dividing_by_zero_when_the_total_is_zero(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        // A zero-amount expense still produces a category row, so the
        // share_pct guard (total > 0 ? ... : 0.0) is actually reached
        // instead of being vacuously true on an empty category list.
        Expense::create([
            'organization_id' => $org->id, 'category' => 'other',
            'expense_date' => '2026-07-02', 'amount' => 0,
        ]);

        $res = $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $res->assertOk();
        $res->assertJsonPath('data.profit.expenses_total', 0);
        $res->assertJsonPath('data.profit.expenses_by_category.0.category', 'other');
        $res->assertJsonPath('data.profit.expenses_by_category.0.share_pct', 0);
    }

    public function test_expenses_outside_the_range_are_excluded(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        Expense::create([
            'organization_id' => $org->id, 'category' => 'rent',
            'expense_date' => '2026-06-30', 'amount' => 999,
        ]);

        $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-01&to=2026-07-31')
            ->assertJsonPath('data.profit.expenses_total', 0);
    }

    public function test_net_profit_can_be_negative(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        Expense::create([
            'organization_id' => $org->id, 'category' => 'equipment',
            'expense_date' => '2026-07-02', 'amount' => 300,
        ]);

        $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-01&to=2026-07-31')
            ->assertJsonPath('data.profit.net_profit', -300);
    }

    public function test_manager_gets_the_report_without_the_profit_block(): void
    {
        $org = $this->makeOrg();
        $manager = $this->makeUser($org, 'manager');

        $res = $this->withToken($this->token($manager))->getJson('/api/reports');

        $res->assertOk();
        $res->assertJsonMissingPath('data.profit');
    }
}
