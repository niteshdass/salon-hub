<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    /**
     * List customers for the current tenant. The global scope on
     * Customer (BelongsToOrganization) restricts this to the resolved
     * organization automatically — no manual where() needed.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Customer::query()
                ->latest('id')
                ->get(['id', 'name', 'email', 'phone', 'organization_id']),
        ]);
    }
}
