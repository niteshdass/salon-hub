<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

/**
 * Branches are org-level configuration: everyone reads them (they fill
 * dropdowns across the dashboard), only the owner writes them.
 *
 * Tenant isolation is separate and already enforced by the global scope —
 * these checks only decide what a role may do inside its own org.
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Branch $branch): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->isOwner();
    }
}
