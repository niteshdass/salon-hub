<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Http\Requests\Service\StoreServiceRequest;
use App\Http\Requests\Service\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
     * appointments.service_id is `cascadeOnDelete`, so deleting a service
     * that has ever been booked destroys those appointments — and, through
     * payments.appointment_id, the payment records against them. There is no
     * SoftDeletes anywhere in this app and no undo, so an owner tidying up
     * last season's menu would silently erase completed bookings and the
     * revenue history the Reports page is built on. Refuse instead; the
     * intended action is almost always `status: inactive`, which hides the
     * service from the booking site and keeps the history.
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
