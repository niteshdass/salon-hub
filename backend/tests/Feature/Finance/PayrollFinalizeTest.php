<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;
use App\Models\Organization;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;

class PayrollFinalizeTest extends FinanceTestCase
{
    /** @return array{0: User, 1: PayrollRun} */
    private function draftRun(Organization $org, string $month = '2026-07-01'): array
    {
        $owner = $this->makeUser($org, 'owner');
        $this->makeStaff($org, ['pay_type' => 'salary', 'monthly_salary' => 1000]);

        $res = $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => $month]);

        return [$owner, PayrollRun::findOrFail($res->json('data.id'))];
    }

    public function test_owner_can_edit_a_line_on_a_draft(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $line = $run->lines()->first();

        $res = $this->withToken($this->token($owner))
            ->patchJson("/api/payroll/runs/{$run->id}/lines/{$line->id}", ['salary_amount' => 600]);

        $res->assertOk();
        $res->assertJsonPath('data.total_amount', '600.00');
        $this->assertSame('600.00', $run->fresh()->total_amount);
        // The computed revenue is untouched, so the override stays visible.
        $this->assertSame('0.00', $line->fresh()->earned_revenue);
    }

    public function test_finalize_locks_the_run_and_writes_one_salary_expense(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);

        $res = $this->withToken($this->token($owner))
            ->postJson("/api/payroll/runs/{$run->id}/finalize");

        $res->assertOk();
        $res->assertJsonPath('data.status', 'finalized');

        $this->assertSame(1, Expense::count());
        $expense = Expense::first();
        $this->assertSame('salary', $expense->category->value);
        $this->assertSame('1000.00', $expense->amount);
        $this->assertSame('2026-07-31', $expense->expense_date->toDateString());
        $this->assertSame($run->id, $expense->payroll_run_id);
        $this->assertSame($owner->id, $run->fresh()->finalized_by);
    }

    public function test_a_finalized_run_cannot_be_edited_or_finalized_again(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $line = $run->lines()->first();
        $token = $this->token($owner);

        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertOk();

        $this->withToken($token)
            ->patchJson("/api/payroll/runs/{$run->id}/lines/{$line->id}", ['salary_amount' => 1])
            ->assertStatus(422);
        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertStatus(422);

        $this->assertSame(1, Expense::count());
    }

    public function test_deleting_a_finalized_run_removes_its_lines_and_salary_expense(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $token = $this->token($owner);
        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertOk();

        $this->withToken($token)->deleteJson("/api/payroll/runs/{$run->id}")->assertNoContent();

        $this->assertSame(0, PayrollRun::count());
        $this->assertSame(0, PayrollLine::count());
        $this->assertSame(0, Expense::count());
    }

    public function test_a_payroll_expense_cannot_be_edited_or_deleted_directly(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $token = $this->token($owner);
        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertOk();
        $expense = Expense::first();

        $this->withToken($token)->patchJson("/api/expenses/{$expense->id}", ['amount' => 5])->assertStatus(422);
        $this->withToken($token)->deleteJson("/api/expenses/{$expense->id}")->assertStatus(422);
        $this->assertSame(1, Expense::count());
    }

    public function test_a_line_from_a_different_run_is_not_found(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org, '2026-07-01');
        $token = $this->token($owner);
        $otherId = $this->withToken($token)
            ->postJson('/api/payroll/runs', ['period_month' => '2026-06-01'])
            ->json('data.id');
        $foreignLine = PayrollRun::findOrFail($otherId)->lines()->first();

        $this->withToken($token)
            ->patchJson("/api/payroll/runs/{$run->id}/lines/{$foreignLine->id}", ['salary_amount' => 1])
            ->assertNotFound();
    }

    public function test_manager_cannot_finalize_or_delete(): void
    {
        $org = $this->makeOrg();
        [, $run] = $this->draftRun($org);
        $token = $this->token($this->makeUser($org, 'manager'));

        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertForbidden();
        $this->withToken($token)->deleteJson("/api/payroll/runs/{$run->id}")->assertForbidden();
    }
}
