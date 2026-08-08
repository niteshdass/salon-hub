<?php

namespace App\Http\Controllers;

use App\Http\Requests\Report\ReportRequest;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __invoke(ReportRequest $request, ReportService $reports): JsonResponse
    {
        ['from' => $from, 'to' => $to] = $request->range();

        $data = $reports->build($from, $to);

        // Costs and profit are owner-only. Managers keep the rest of the
        // report rather than losing a page they legitimately use.
        if (! $request->user()->isOwner()) {
            unset($data['profit']);
        }

        return response()->json(['data' => $data]);
    }
}
