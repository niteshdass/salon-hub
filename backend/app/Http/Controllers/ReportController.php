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

        return response()->json(['data' => $reports->build($from, $to)]);
    }
}
