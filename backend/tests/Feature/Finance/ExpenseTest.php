<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;
use App\Models\Organization;
use App\Models\PayrollRun;

class ExpenseTest extends FinanceTestCase
{
    public function test_owner_can_log_an_expense(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))->postJson('/api/expenses', [
            'category' => 'rent',
            'expense_date' => '2026-07-01',
            'amount' => 450.50,
            'note' => 'July rent',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.category', 'rent');
        $res->assertJsonPath('data.amount', '450.50');
        $this->assertSame($owner->id, Expense::first()->recorded_by);
    }

    public function test_expenses_are_listed_newest_first_within_the_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $this->expense($org, ['expense_date' => '2026-07-01', 'amount' => 100]);
        $this->expense($org, ['expense_date' => '2026-07-20', 'amount' => 200]);
        $this->expense($org, ['expense_date' => '2026-06-01', 'amount' => 300]);

        $res = $this->withToken($this->token($owner))
            ->getJson('/api/expenses?from=2026-07-01&to=2026-07-31');

        $res->assertOk();
        $res->assertJsonCount(2, 'data');
        $res->assertJsonPath('data.0.amount', '200.00');
    }

    public function test_a_zero_amount_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->postJson('/api/expenses', ['category' => 'rent', 'expense_date' => '2026-07-01', 'amount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_future_dated_expense_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->postJson('/api/expenses', [
                'category' => 'rent',
                'expense_date' => now()->addDay()->toDateString(),
                'amount' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense_date');
    }

    public function test_owner_can_update_and_delete_an_expense(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $expense = $this->expense($org, ['amount' => 100]);
        $token = $this->token($owner);

        $this->withToken($token)
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 125])
            ->assertOk()
            ->assertJsonPath('data.amount', '125.00');

        $this->withToken($token)->deleteJson("/api/expenses/{$expense->id}")->assertNoContent();
        $this->assertSame(0, Expense::count());
    }

    public function test_manager_and_staff_cannot_touch_expenses(): void
    {
        $org = $this->makeOrg();
        $expense = $this->expense($org);

        foreach (['manager', 'staff'] as $role) {
            $token = $this->token($this->makeUser($org, $role));
            $this->withToken($token)->getJson('/api/expenses')->assertForbidden();
            $this->withToken($token)->postJson('/api/expenses', [
                'category' => 'rent', 'expense_date' => '2026-07-01', 'amount' => 10,
            ])->assertForbidden();
            $this->withToken($token)->patchJson("/api/expenses/{$expense->id}", ['amount' => 20])
                ->assertForbidden();
            $this->withToken($token)->deleteJson("/api/expenses/{$expense->id}")->assertForbidden();
        }
    }

    public function test_a_payroll_generated_expense_rejects_update(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $expense = $this->expense($org, ['amount' => 100]);
        $run = PayrollRun::create(['organization_id' => $org->id, 'period_month' => '2026-07-01', 'status' => 'finalized']);
        $expense->forceFill(['payroll_run_id' => $run->id])->save();

        $this->withToken($this->token($owner))
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 999])
            ->assertStatus(422)
            ->assertJson(['message' => 'This expense comes from a payroll run. Change the run instead.']);

        $this->assertSame('100.00', $expense->fresh()->amount);
    }

    public function test_a_payroll_generated_expense_rejects_destroy(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $expense = $this->expense($org, ['amount' => 100]);
        $run = PayrollRun::create(['organization_id' => $org->id, 'period_month' => '2026-07-01', 'status' => 'finalized']);
        $expense->forceFill(['payroll_run_id' => $run->id])->save();

        $this->withToken($this->token($owner))
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertStatus(422)
            ->assertJson(['message' => 'This expense comes from a payroll run. Delete the run instead.']);

        $this->assertSame(1, Expense::count());
    }

    public function test_another_tenants_expense_is_not_found(): void
    {
        $org = $this->makeOrg();
        $other = $this->makeOrg('other');
        $owner = $this->makeUser($org, 'owner');
        $theirs = $this->expense($other);

        $this->withToken($this->token($owner))
            ->patchJson("/api/expenses/{$theirs->id}", ['amount' => 1])
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function expense(Organization $org, array $overrides = []): Expense
    {
        return Expense::create([
            'organization_id' => $org->id,
            'category' => $overrides['category'] ?? 'supplies',
            'expense_date' => $overrides['expense_date'] ?? '2026-07-10',
            'amount' => $overrides['amount'] ?? 50,
            'note' => $overrides['note'] ?? null,
        ]);
    }
}
