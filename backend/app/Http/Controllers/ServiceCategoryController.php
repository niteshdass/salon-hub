<?php

namespace App\Http\Controllers;

use App\Http\Requests\Service\StoreServiceCategoryRequest;
use App\Http\Requests\Service\UpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ServiceCategoryController extends Controller
{
    /**
     * ServiceCategory is auto-scoped by BelongsToOrganization; implicit
     * route-model binding is therefore tenant-scoped (404 cross-tenant).
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ServiceCategory::class);

        return ServiceCategoryResource::collection(
            ServiceCategory::withCount('services')->latest('id')->get()
        );
    }

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $category = ServiceCategory::create($request->validated());

        return (new ServiceCategoryResource($category->loadCount('services')))
            ->response()->setStatusCode(201);
    }

    public function show(ServiceCategory $category): ServiceCategoryResource
    {
        $this->authorize('view', $category);

        return new ServiceCategoryResource($category->loadCount('services'));
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $category): ServiceCategoryResource
    {
        $category->update($request->validated());

        return new ServiceCategoryResource($category->loadCount('services'));
    }

    public function destroy(ServiceCategory $category): Response
    {
        $this->authorize('delete', $category);

        $category->delete();

        return response()->noContent();
    }
}
