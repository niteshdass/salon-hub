<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Organization;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active tenant for the PUBLIC (no-auth) booking site.
 *
 * Resolution order:
 *   1. The {org} route parameter (a slug) -> an ACTIVE organization.
 *   2. Host header -> Domain lookup (real subdomain / custom-domain prod).
 *
 * A missing / inactive organization aborts with 404. Runs BEFORE
 * SubstituteBindings so implicit route-model binding (e.g. {service}) is
 * filtered by the tenant's global scope and a cross-tenant id yields a 404.
 */
class ResolvePublicTenant
{
    public function __construct(protected CurrentTenant $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($slug = $request->route('org')) {
            $organization = Organization::where('slug', $slug)
                ->where('status', 'active')
                ->first();
        } else {
            $organization = Domain::where('domain', $request->getHost())
                ->first()?->organization;
        }

        if (! $organization) {
            abort(404);
        }

        $this->tenant->set($organization);

        return $next($request);
    }
}
