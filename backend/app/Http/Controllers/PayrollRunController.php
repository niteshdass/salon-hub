<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Enums\PayrollRunStatus;
use App\Http\Requests\Payroll\StorePayrollRunRequest;
use App\Http\Resources\PayrollRunResource;
use App\Models\PayrollRun;
use App\Services\PayrollCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Monthly staff pay. Runs are auto-scoped by BelongsToOrganization, so
 * route-model binding cannot reach another tenant's payroll (a foreign id
 * 404s). Every ability is owner-only, reads included.
 */
class PayrollRunController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::query()->orderByDesc('period_month')->get();

        return PayrollRunResource::collection($runs);
    }

    public function store(StorePayrollRunRequest $request, PayrollCalculator $calculator): JsonResponse
    {
        $month = $request->periodMonth();

        // Checked here for a readable message; the unique index is the backstop.
        if (PayrollRun::query()->whereDate('period_month', $month->toDateString())->exists()) {
            return response()->json([
                'message' => 'Payroll for '.$month->format('F Y').' already exists.',
            ], 422);
        }

        $run = DB::transaction(function () use ($month, $calculator) {
            $run = PayrollRun::create(['period_month' => $month->toDateString()]);

            foreach ($calculator->linesFor($month) as $line) {
                $run->lines()->create($line);
            }

            $run->syncTotals();

            return $run;
        });

        return (new PayrollRunResource($run->fresh()->load('lines')))
            ->response()->setStatusCode(201);
    }

    public function show(PayrollRun $run): JsonResponse
    {
        $this->authorize('view', $run);

        return (new PayrollRunResource($run->load('lines')))->response();
    }

    /**
     * Lock the run and book it as a cost. The salary expense is what makes
     * staff pay show up in the P&L, and it is written here — once — so the
     * two can never drift apart.
     */
    public function finalize(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('update', $run);

        // The draft check has to happen against a locked row inside the
        // transaction, not against the instance route-model binding handed us:
        // two clicks on Finalize can both read a draft, and if both proceed
        // the run is booked as a cost twice. A unique index on
        // expenses.payroll_run_id backs this up at the database.
        $finalized = DB::transaction(function () use ($request, $run) {
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isDraft()) {
                return false;
            }

            $locked->syncTotals();
            $locked->refresh();

            $locked->update([
                'status' => PayrollRunStatus::FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $request->user()->id,
            ]);

            $locked->expense()->create([
                'organization_id' => $locked->organization_id,
                'category' => ExpenseCategory::SALARY,
                'expense_date' => $locked->period_month->copy()->endOfMonth()->toDateString(),
                // Tips ride through payroll but are never the salon's cost —
                // costAmount() strips them so the P&L never double-counts
                // money that was always the stylist's.
                'amount' => $locked->costAmount(),
                'note' => 'Payroll — '.$locked->period_month->format('F Y'),
                'recorded_by' => $request->user()->id,
            ]);

            return true;
        });

        if (! $finalized) {
            return response()->json(['message' => 'This payroll run is already finalized.'], 422);
        }

        return (new PayrollRunResource($run->fresh()->load('lines')))->response();
    }

    /**
     * Correcting a finalized month means deleting it and running it again.
     * Lines and the salary expense go with it (both cascade).
     */
    public function destroy(PayrollRun $run): Response
    {
        $this->authorize('delete', $run);

        $run->delete();

        return response()->noContent();
    }
}
