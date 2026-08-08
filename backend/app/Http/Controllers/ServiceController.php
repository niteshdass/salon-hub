<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Http\Requests\Service\BulkStoreServiceRequest;
use App\Http\Requests\Service\StoreServiceRequest;
use App\Http\Requests\Service\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    /**
     * Service is auto-scoped by BelongsToOrganization; implicit
     * route-model binding is therefore tenant-scoped (404 cross-tenant).
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Service::class);

        return ServiceResource::collection(
            Service::with('category')->latest('id')->get()
        );
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= ServiceStatus::ACTIVE->value;

        $service = Service::create($data);

        return (new ServiceResource($service->load('category')))
            ->response()->setStatusCode(201);
    }

    /**
     * Create a whole starter menu in one transaction, for the onboarding
     * wizard's second screen.
     *
     * The salon type becomes a category so the public page gets its
     * grouping without asking the owner a second question. firstOrCreate,
     * not create: an owner who walks the wizard twice — or backs up and
     * continues — must not end up with two "Hair salon" categories.
     */
    public function bulkStore(BulkStoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenantId = app(CurrentTenant::class)->id();

        $services = DB::transaction(function () use ($data, $tenantId) {
            $category = ServiceCategory::firstOrCreate([
                'organization_id' => $tenantId,
                'name' => $data['category'],
            ]);

            return collect($data['rows'])->map(fn (array $row) => Service::create([
                'category_id' => $category->id,
                'name' => $row['name'],
                'duration' => $row['duration'],
                'price' => $row['price'],
                'status' => ServiceStatus::ACTIVE->value,
            ]))->all();
        });

        return ServiceResource::collection(
            Service::with('category')->whereIn('id', collect($services)->pluck('id'))->get()
        )->response()->setStatusCode(201);
    }

    public function show(Service $service): ServiceResource
    {
        $this->authorize('view', $service);

        return new ServiceResource($service->load('category'));
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $service->update($request->validated());

        return new ServiceResource($service->load('category'));
    }

    /**
     * A service with visits behind it cannot be deleted. Its line items snapshot
     * the name and price, so history would in fact survive — but the owner would
     * lose the ability to report on that service by id, and the intended action
     * is almost always `status: inactive`, which hides it from the booking site
     * and keeps everything. Refuse and say so.
     */
    public function destroy(Service $service): Response|JsonResponse
    {
        $this->authorize('delete', $service);

        if ($service->appointments()->exists()) {
            return response()->json([
                'message' => 'This service has appointments booked against it and cannot be deleted. Set it to inactive instead to hide it from your booking site.',
            ], 422);
        }

        $service->delete();

        return response()->noContent();
    }
}
