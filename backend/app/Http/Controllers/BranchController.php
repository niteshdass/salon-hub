<?php

namespace App\Http\Controllers;

use App\Http\Requests\Branch\StoreBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\PlanLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BranchController extends Controller
{
    /**
     * Branch is auto-scoped by BelongsToOrganization, so every query
     * here — including implicit route-model binding — is restricted to
     * the current tenant. A cross-tenant id resolves to a 404.
     */
    public function index(): AnonymousResourceCollection
    {
        return BranchResource::collection(Branch::query()->latest('id')->get());
    }

    public function store(StoreBranchRequest $request, PlanLimit $planLimit): JsonResponse
    {
        if (! $planLimit->canAddBranch()) {
            return response()->json(['message' => 'Your free plan allows only 1 branch.'], 422);
        }

        $branch = Branch::create($request->validated());

        return (new BranchResource($branch))->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $branch->update($request->validated());

        return new BranchResource($branch);
    }

    public function destroy(Branch $branch): Response
    {
        $branch->delete();

        return response()->noContent();
    }
}
