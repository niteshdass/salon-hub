<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active tenant for the request.
 *
 * Resolution order:
 *   1. Authenticated user's organization (dashboard/API context).
 *   2. Host header -> Domain lookup (public booking site context).
 *
 * Must run AFTER auth:sanctum so $request->user() is populated.
 */
class ResolveTenant
{
    public function __construct(protected CurrentTenant $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (($user = $request->user()) && $user->organization) {
            $this->tenant->set($user->organization);
        } elseif ($domain = Domain::where('domain', $request->getHost())->first()) {
            $this->tenant->set($domain->organization);
        }

        return $next($request);
    }
}
