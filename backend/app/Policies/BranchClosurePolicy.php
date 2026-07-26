<?php

namespace App\Policies;

use App\Models\BranchClosure;
use App\Models\User;

/**
 * Declaring a branch (or the whole salon) closed is a scheduling decision
 * reserved for an owner or manager; front-desk staff may view closures but
 * not change them. The tenant scope resolves which rows are visible, so these
 * rules depend on the actor's role alone.
 */
class BranchClosurePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function delete(User $user, BranchClosure $closure): bool
    {
        return $user->isManagerOrOwner();
    }
}
