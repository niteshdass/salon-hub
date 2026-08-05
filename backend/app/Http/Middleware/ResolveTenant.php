<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
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

        $this->tagSentryScope();

        return $next($request);
    }

    /**
     * Attach the tenant to any error reported from this request, so a spike
     * can be traced to one salon rather than the whole platform. Placed once
     * after both resolution branches (rather than duplicated in each, as
     * literally shown in the plan) since `$this->tenant->get()` already
     * covers "no tenant resolved" — same behaviour, no duplicated block.
     *
     * `app()->bound('sentry')` and `\Sentry\configureScope()` are both cheap
     * with no DSN configured: the Sentry Hub is bound to the container
     * unconditionally by the package's ServiceProvider::boot(), and
     * `configureScope()` only mutates the in-process Scope object — no
     * network call, no DSN check — so this runs safely on every request in
     * local dev, CI and the test suite.
     */
    private function tagSentryScope(): void
    {
        if (app()->bound('sentry') && ($organization = $this->tenant->get())) {
            \Sentry\configureScope(function (Scope $scope) use ($organization): void {
                $scope->setTag('organization_id', (string) $organization->id);
                $scope->setTag('organization_slug', $organization->slug);
            });
        }
    }
}
