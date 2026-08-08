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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class PayrollFinalizeTest extends FinanceTestCase
{
    /** @return array{0: User, 1: PayrollRun, 2: User} owner, run, staff */
    private function draftRun(Organization $org, string $month = '2026-07-01'): array
    {
        $owner = $this->makeUser($org, 'owner');
        $staff = $this->makeStaff($org, ['pay_type' => 'salary', 'monthly_salary' => 1000]);
        // A completed booking inside the period, so earned_revenue is a real
        // number rather than zero: "the API ignored what the client sent"
        // proves nothing when the computed value would have been 0 anyway.
        $this->makeAppointment($org, [
            'staff' => $staff,
            'date' => Carbon::parse($month)->addDays(14)->toDateString(),
            'price' => 250,
        ]);

        $res = $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => $month]);

        return [$owner, PayrollRun::findOrFail($res->json('data.id')), $staff];
    }

    public function test_owner_can_edit_a_line_on_a_draft_and_computed_fields_stay_computed(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $line = $run->lines()->first();
        $this->assertSame('250.00', $line->earned_revenue);
        $this->assertSame(1, $line->bookings);

        $res = $this->withToken($this->token($owner))
            ->patchJson("/api/payroll/runs/{$run->id}/lines/{$line->id}", [
                'salary_amount' => 600,
                // Not editable: sent anyway, and must be dropped on the floor.
                'earned_revenue' => 99999,
                'bookings' => 42,
            ]);

        $res->assertOk();
        $res->assertJsonPath('data.total_amount', '600.00');
        $this->assertSame('600.00', $run->fresh()->total_amount);
        // The computed revenue is untouched, so the override stays visible
        // against the reality it departs from.
        $this->assertSame('250.00', $line->fresh()->earned_revenue);
        $this->assertSame(1, $line->fresh()->bookings);
    }

    /**
     * A tip rides on the line's total but is never itself editable.
     * Editing the commission must re-run totalFor() (salary + commission +
     * tips), not silently drop the tip the staff member already earned.
     */
    public function test_editing_a_lines_commission_keeps_its_tip_in_the_total(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $staff = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 50]);
        $appointment = $this->makeAppointment($org, [
            'staff' => $staff,
            'date' => '2026-07-14',
            'price' => 100,
        ]);
        $appointment->payments()->create([
            'organization_id' => $org->id,
            'amount' => 100, 'tip_amount' => 20,
            'method' => 'cash', 'status' => 'verified', 'source' => 'staff',
        ]);

        $token = $this->token($owner);
        $res = $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-07-01']);
        $run = PayrollRun::findOrFail($res->json('data.id'));
        $line = $run->lines()->first();

        $this->assertSame('50.00', $line->commission_amount);
        $this->assertSame('20.00', $line->tips_amount);
        $this->assertSame('70.00', $line->total_amount);

        $res = $this->withToken($token)
            ->patchJson("/api/payroll/runs/{$run->id}/lines/{$line->id}", [
                'commission_amount' => 80,
            ]);

        $res->assertOk();
        $res->assertJsonPath('data.commission_amount', '80.00');
        $res->assertJsonPath('data.tips_amount', '20.00');
        $res->assertJsonPath('data.total_amount', '100.00');
        $this->assertSame('20.00', $line->fresh()->tips_amount);
        $this->assertSame('100.00', $line->fresh()->total_amount);
    }

    /**
     * The whole reason the line carries its own pay_type / rate / salary
     * columns instead of reading them off the staff profile: a later raise
     * must not silently rewrite what a past month says it paid.
     */
    public function test_a_raise_after_finalizing_does_not_rewrite_the_finalized_line(): void
    {
        $org = $this->makeOrg();
        [$owner, $run, $staff] = $this->draftRun($org);
        $token = $this->token($owner);
        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertOk();
        $line = $run->lines()->first();

        $this->withToken($token)->patchJson("/api/staff/{$staff->id}", [
            'pay_type' => 'hybrid',
            'monthly_salary' => 2500,
            'commission_rate' => 30,
        ])->assertOk();

        $after = $line->fresh();
        $this->assertSame('salary', $after->pay_type->value);
        $this->assertSame('0.00', $after->commission_rate);
        $this->assertSame('1000.00', $after->monthly_salary);
        $this->assertSame('1000.00', $after->salary_amount);
        $this->assertSame('0.00', $after->commission_amount);
        $this->assertSame('1000.00', $after->total_amount);
        // …and neither the run header nor the booked cost moves either.
        $this->assertSame('1000.00', $run->fresh()->total_amount);
        $this->assertSame('1000.00', Expense::first()->amount);
    }

    /**
     * The reason staff_name is snapshotted and staff_id is nullOnDelete:
     * losing a colleague must not erase the record of what they were paid.
     */
    public function test_deleting_a_staff_account_leaves_the_pay_line_and_its_name(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        // No appointments: StaffController refuses to delete a stylist who
        // has any, so this is the only shape in which the case can arise.
        $staff = $this->makeStaff($org, ['pay_type' => 'salary', 'monthly_salary' => 1000], 'Ruma Akter');
        $token = $this->token($owner);

        $runId = $this->withToken($token)
            ->postJson('/api/payroll/runs', ['period_month' => '2026-07-01'])->json('data.id');
        $this->withToken($token)->postJson("/api/payroll/runs/{$runId}/finalize")->assertOk();
        $line = PayrollRun::findOrFail($runId)->lines()->first();
        $this->assertSame($staff->id, $line->staff_id);

        $this->withToken($token)->deleteJson("/api/staff/{$staff->id}")->assertNoContent();

        $after = $line->fresh();
        $this->assertNotNull($after, 'the pay line must outlive the staff account');
        $this->assertNull($after->staff_id);
        $this->assertSame('Ruma Akter', $after->staff_name);
        $this->assertSame('salary', $after->pay_type->value);
        $this->assertSame('1000.00', $after->total_amount);
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
        $this->makeExpense($org);
        $this->makeExpense($org);
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

    public function test_manager_and_staff_cannot_finalize_delete_or_edit_a_line(): void
    {
        $org = $this->makeOrg();
        [, $run] = $this->draftRun($org);
        $line = $run->lines()->first();

        foreach (['manager', 'staff'] as $role) {
            $token = $this->token($this->makeUser($org, $role));

            $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertForbidden();
            $this->withToken($token)->deleteJson("/api/payroll/runs/{$run->id}")->assertForbidden();
            $this->withToken($token)
                ->patchJson("/api/payroll/runs/{$run->id}/lines/{$line->id}", ['salary_amount' => 1])
                ->assertForbidden();
        }

        $this->assertSame('1000.00', $line->fresh()->salary_amount);
    }
}
