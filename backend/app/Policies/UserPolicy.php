<?php

namespace App\Policies;

use App\Models\User;

/**
 * Staff management. StaffController resolves staff by id manually (User is
 * the auth model and has no tenant global scope), so these abilities are
 * checked at class level — every rule here depends on the actor's role
 * alone, never on the target row.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function update(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function delete(User $user): bool
    {
        return $user->isManagerOrOwner();
    }
}
