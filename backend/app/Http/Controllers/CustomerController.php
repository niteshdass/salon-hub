<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Customer is auto-scoped by BelongsToOrganization (global scope +
 * auto-fill), so every query here — including implicit route-model
 * binding — is restricted to the current tenant. A cross-tenant id
 * resolves to a 404.
 */
class CustomerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        return CustomerResource::collection(Customer::query()->latest('id')->get());
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('view', $customer);

        return new CustomerResource($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    /**
     * appointments.customer_id is `cascadeOnDelete` and payments cascade from
     * appointments, so deleting a customer who has ever booked destroys their
     * entire visit and payment history with no undo (no model here uses
     * SoftDeletes). Refuse while dependent appointments exist.
     */
    public function destroy(Customer $customer): Response|JsonResponse
    {
        $this->authorize('delete', $customer);

        if ($customer->appointments()->exists()) {
            return response()->json([
                'message' => 'This customer has appointments in their history and cannot be deleted.',
            ], 422);
        }

        $customer->delete();

        return response()->noContent();
    }
}
