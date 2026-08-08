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
