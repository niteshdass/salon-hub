<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The salon's costs. Expenses are auto-scoped by BelongsToOrganization, so
 * route-model binding cannot reach another tenant's row (a foreign id 404s).
 *
 * Rows created by a finalized payroll run are read-only here: they change
 * only when their run does, which keeps payroll and the P&L in step.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Expense::class);

        // Default window is the current month — the log is a month view, and
        // an unbounded list grows without limit.
        $to = $request->date('to') ?? Carbon::now(config('app.timezone'))->startOfDay();
        $from = $request->date('from') ?? $to->copy()->startOfMonth();

        $expenses = Expense::query()
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->with('recorder')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        return ExpenseResource::collection($expenses);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = Expense::create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return (new ExpenseResource($expense))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        if ($expense->isSystemGenerated()) {
            return response()->json([
                'message' => 'This expense comes from a payroll run. Change the run instead.',
            ], 422);
        }

        $expense->update($request->validated());

        return (new ExpenseResource($expense->fresh()))->response();
    }

    public function destroy(Expense $expense): Response|JsonResponse
    {
        $this->authorize('delete', $expense);

        if ($expense->isSystemGenerated()) {
            return response()->json([
                'message' => 'This expense comes from a payroll run. Delete the run instead.',
            ], 422);
        }

        $expense->delete();

        return response()->noContent();
    }
}
