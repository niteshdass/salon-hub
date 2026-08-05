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
 * The Host-header branch goes through the same
 * Domain::resolveOrganizationForHost rule as the public resolver, so there
 * is exactly one answer in the codebase to "which Domain rows may name a
 * tenant" — a second, looser copy here would be the wider door.
 *
 * Must run AFTER auth:sanctum so $request->user() is populated.
 */
class ResolveTenant
{
    public function __construct(protected CurrentTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (($user = $request->user()) && $user->organization) {
            $this->tenant->set($user->organization);
        } elseif ($organization = Domain::resolveOrganizationForHost($request->getHost())) {
            $this->tenant->set($organization);
        }

        return $next($request);
    }
}
