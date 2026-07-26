<?php

namespace App\Http\Controllers;

use App\Http\Requests\BranchClosure\StoreBranchClosureRequest;
use App\Http\Resources\BranchClosureResource;
use App\Models\BranchClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Branch (or salon-wide) closures. BranchClosure carries the tenant global
 * scope, so the list and route-model binding are already limited to the
 * current organization — a foreign closure id resolves to a 404.
 */
class BranchClosureController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BranchClosure::class);

        $closures = BranchClosure::query()
            ->orderBy('start_date')
            ->get();

        return BranchClosureResource::collection($closures);
    }

    public function store(StoreBranchClosureRequest $request): JsonResponse
    {
        $closure = BranchClosure::create($request->validated());

        return (new BranchClosureResource($closure))->response()->setStatusCode(201);
    }

    public function destroy(BranchClosure $branchClosure): Response
    {
        $this->authorize('delete', $branchClosure);

        $branchClosure->delete();

        return response()->noContent();
    }
}
