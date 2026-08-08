<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseCategory;
use App\Enums\PayrollRunStatus;
use App\Models\Expense;
use App\Models\Organization;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class PayrollFinalizeTest extends FinanceTestCase
{
    /** A hand-logged expense: no payroll run behind it. */
    private function expense(Organization $org): Expense
    {
        return Expense::create([
            'organization_id' => $org->id,
            'category' => ExpenseCategory::SUPPLIES->value,
            'expense_date' => '2026-07-10',
            'amount' => 40,
        ]);
    }

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

    /**
     * The double-click case. Two finalize requests can both read the run as a
     * draft before either commits; without a re-read under a row lock the
     * second one runs the whole body again and books a second salary expense,
     * which the P&L then counts on top of the first.
     *
     * Simulated deterministically: the listener fires on the first PayrollRun
     * read of the request — route-model binding — and does what the winning
     * request would have done. The controller's own instance is stale from
     * that moment on, so only a fresh locked read can catch it.
     */
    public function test_a_finalize_that_loses_the_race_does_not_book_a_second_salary_expense(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);

        $raced = false;
        Event::listen('eloquent.retrieved: '.PayrollRun::class, function () use (&$raced, $org, $owner, $run) {
            if ($raced) {
                return;
            }
            $raced = true;

            // Raw queries: the winning request's work, with no Eloquent reads
            // of its own that would re-enter this listener.
            DB::table('payroll_runs')->where('id', $run->id)->update([
                'status' => PayrollRunStatus::FINALIZED->value,
                'finalized_at' => now(),
                'finalized_by' => $owner->id,
            ]);
            DB::table('expenses')->insert([
                'organization_id' => $org->id,
                'payroll_run_id' => $run->id,
                'category' => ExpenseCategory::SALARY->value,
                'expense_date' => '2026-07-31',
                'amount' => 1000,
                'recorded_by' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->withToken($this->token($owner))
            ->postJson("/api/payroll/runs/{$run->id}/finalize")
            ->assertStatus(422);

        $this->assertSame(1, Expense::count());
    }

    /**
     * The database backstop under the row lock: one payroll run can own at
     * most one expense, whatever the application layer does.
     */
    public function test_the_database_refuses_a_second_expense_for_one_payroll_run(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $this->withToken($this->token($owner))
            ->postJson("/api/payroll/runs/{$run->id}/finalize")->assertOk();

        // The index is on a nullable column, so hand-logged expenses — which
        // carry no run — are still unlimited.
        $this->expense($org);
        $this->expense($org);
        $this->assertSame(3, Expense::count());

        $this->expectException(QueryException::class);

        Expense::create([
            'organization_id' => $org->id,
            'payroll_run_id' => $run->id,
            'category' => ExpenseCategory::SALARY->value,
            'expense_date' => '2026-07-31',
            'amount' => 1000,
        ]);
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
