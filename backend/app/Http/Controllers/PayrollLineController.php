<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payroll\UpdatePayrollLineRequest;
use App\Http\Resources\PayrollLineResource;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;

/**
 * One staff member's row inside a payroll run. The run is tenant-scoped by
 * its model; the line is checked against that run so a valid id under the
 * wrong run 404s rather than editing the wrong month.
 */
class PayrollLineController extends Controller
{
    public function update(
        UpdatePayrollLineRequest $request,
        PayrollRun $run,
        PayrollLine $line,
        PayrollRunController $runs,
    ): JsonResponse {
        abort_unless($line->payroll_run_id === $run->id, 404);

        if (! $run->isDraft()) {
            return response()->json([
                'message' => 'This payroll run is finalized. Delete it and create it again to change it.',
            ], 422);
        }

        $data = $request->validated();
        $line->fill($data);
        $line->total_amount = round((float) $line->salary_amount + (float) $line->commission_amount, 2);
        $line->save();

        $runs->syncTotals($run);

        return (new PayrollLineResource($line->fresh()))->response();
    }
}
