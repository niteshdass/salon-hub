<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Enforces per-plan resource limits for the CURRENT tenant.
 *
 * Only the `free` plan is capped for now (1 branch, 10 staff); every
 * other plan is treated as unlimited. All checks read the tenant that
 * ResolveTenant bound for the request.
 */
class PlanLimit
{
    public const FREE_MAX_BRANCHES = 1;

    public const FREE_MAX_STAFF = 10;

    public function __construct(protected CurrentTenant $tenant) {}

    /**
     * Whether the current tenant may create another branch.
     *
     * Branch is auto-scoped by BelongsToOrganization, so count() is
     * already restricted to the current organization.
     */
    public function canAddBranch(): bool
    {
        if (! $this->isFreePlan()) {
            return true;
        }

        return Branch::count() < self::FREE_MAX_BRANCHES;
    }

    /**
     * Whether the current tenant may create another staff member.
     *
     * User is NOT auto-scoped (it is the auth model), so scope manually.
     */
    public function canAddStaff(): bool
    {
        if (! $this->isFreePlan()) {
            return true;
        }

        $count = User::query()
            ->where('organization_id', $this->tenant->id())
            ->where('role', UserRole::STAFF->value)
            ->count();

        return $count < self::FREE_MAX_STAFF;
    }

    protected function isFreePlan(): bool
    {
        $plan = $this->tenant->get()?->subscription_plan;
        $value = $plan instanceof \BackedEnum ? $plan->value : $plan;

        return $value === SubscriptionPlan::FREE->value;
    }
}
