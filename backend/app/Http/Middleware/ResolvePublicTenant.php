<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Organization;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active tenant for the PUBLIC (no-auth) booking site.
 *
 * Resolution order:
 *   1. The {org} route parameter (a slug) -> an ACTIVE organization.
 *   2. Host header -> Domain lookup (the salon's own <slug>.APP_DOMAIN).
 *
 * Both branches demand an ACTIVE organization: the Host header selects which
 * tenant's customers, bookings and revenue are served, so the door opened by
 * a subdomain is never wider than the door opened by a slug. Which Domain
 * rows are allowed to answer is decided in Domain::resolveOrganizationForHost.
 *
 * A missing / inactive organization aborts with 404. Runs BEFORE
 * SubstituteBindings so implicit route-model binding (e.g. {service}) is
 * filtered by the tenant's global scope and a cross-tenant id yields a 404.
 */
class ResolvePublicTenant
{
    public function __construct(protected CurrentTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($slug = $request->route('org')) {
            $organization = Organization::where('slug', $slug)
                ->where('status', 'active')
                ->first();
        } else {
            $organization = Domain::resolveOrganizationForHost($request->getHost());
        }

        if (! $organization) {
            abort(404);
        }

        $this->tenant->set($organization);

        $this->tagSentryScope($organization);

        return $next($request);
    }

    /**
     * Attach the tenant to any error reported from this request. Not named
     * in the plan (which only touches ResolveTenant), added here too
     * because this is the middleware that actually guards the public
     * booking flow — book, manage/{token}, and the payment gateway
     * callbacks all run through here, not through ResolveTenant, whose
     * Host-header branch is only a fallback on authenticated routes. A
     * booking-flow 500 on a salon subdomain is exactly the case this task
     * wants traceable to one salon. See ResolveTenant::tagSentryScope() for
     * why this call is safe and cheap with no DSN configured.
     */
    private function tagSentryScope(Organization $organization): void
    {
        if (app()->bound('sentry')) {
            \Sentry\configureScope(function (Scope $scope) use ($organization): void {
                $scope->setTag('organization_id', (string) $organization->id);
                $scope->setTag('organization_slug', $organization->slug);
            });
        }
    }
}
