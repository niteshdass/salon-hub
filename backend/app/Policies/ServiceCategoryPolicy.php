<?php

namespace App\Policies;

use App\Models\ServiceCategory;
use App\Models\User;

/**
 * Categories follow the service catalogue rules: owner/manager write,
 * everyone reads.
 */
class ServiceCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceCategory $category): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return $user->isManagerOrOwner();
    }

    public function delete(User $user, ServiceCategory $category): bool
    {
        return $user->isManagerOrOwner();
    }
}
