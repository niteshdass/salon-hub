<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationStatus;
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
 *
 * FAILS CLOSED FOR AUTHENTICATED REQUESTS. An authenticated request that
 * resolves no ACTIVE organization is refused here rather than allowed
 * through with no tenant bound. Two reasons, both concrete:
 *
 *  - BelongsToOrganization's global scope is deliberately INERT when no
 *    tenant is bound — registration, login, host resolution, the whole
 *    customer-account portal and the seeders all depend on that, and
 *    TenantScopingTest asserts it. So "no tenant bound" on a route inside
 *    the `tenant` group does not mean "see nothing", it means "see every
 *    tenant". A user whose organization row went away (hand-written DELETE,
 *    or a dangling organization_id on a box running with
 *    foreign_key_checks off) would turn every unexpired Sanctum token into
 *    an unscoped read/write credential across the whole database. Closing
 *    it here rather than in the trait keeps the inert-scope contract the
 *    other three subsystems rely on.
 *  - Status was previously checked only by Domain::resolveOrganizationForHost
 *    and ResolvePublicTenant, so suspending a salon took down its public
 *    booking site and left its dashboard, API, reports and payment settings
 *    fully operational. Now suspension means the same thing on both sides.
 *
 * Scope of the refusal: the `tenant` alias is applied to the authenticated
 * group in routes/api.php, plus the two session-shaped routes outside it
 * (`auth/me` and `/user`). Public routes use `public.tenant`, which already
 * 404s, and the customer-account group has no tenant middleware at all —
 * neither is affected. `auth/logout` is deliberately left outside: a
 * suspended member must still be able to sign out.
 */
class ResolveTenant
{
    public function __construct(protected CurrentTenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $organization = $user->organization;

            abort_unless(
                $organization && $organization->status === OrganizationStatus::ACTIVE,
                403,
                self::refusalMessage($organization?->status)
            );

            $this->tenant->set($organization);
        } elseif ($organization = Domain::resolveOrganizationForHost($request->getHost())) {
            $this->tenant->set($organization);
        }

        $this->tagSentryScope();

        return $next($request);
    }

    /**
     * Why the request was refused, in words the SPA can show the person
     * holding the token.
     *
     * The refusal is the only thing a suspended owner will see, so a single
     * generic string would leave them staring at "something went wrong" with
     * no idea their salon was suspended. These are deliberately not specific
     * about *why* the account is in that state — that is a support
     * conversation, not an API response — but they do name the state, which
     * is the difference between an actionable message and a dead end.
     */
    private static function refusalMessage(?OrganizationStatus $status): string
    {
        return match ($status) {
            OrganizationStatus::SUSPENDED => 'This salon account has been suspended. Please contact support.',
            OrganizationStatus::INACTIVE => 'This salon account is inactive. Please contact support.',
            // No organization at all: the row was removed out from under a
            // still-valid token, or the account was never linked to one.
            default => 'This account is not linked to a salon. Please contact support.',
        };
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
