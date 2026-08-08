<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payroll\StorePayrollRunRequest;
use App\Http\Resources\PayrollRunResource;
use App\Models\PayrollRun;
use App\Services\PayrollCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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

            $this->syncTotals($run);

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
     * Recompute the run's totals from its lines. Called on create and again
     * after every line edit, so the header always matches the rows under it.
     */
    public function syncTotals(PayrollRun $run): void
    {
        $lines = $run->lines()->get();

        $run->update([
            'total_salary' => round((float) $lines->sum('salary_amount'), 2),
            'total_commission' => round((float) $lines->sum('commission_amount'), 2),
            'total_amount' => round((float) $lines->sum('total_amount'), 2),
        ]);
    }
}
