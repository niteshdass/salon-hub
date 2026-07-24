<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

/**
 * The service catalogue is operational data: owner and manager maintain
 * it, staff read it (they need service names on their schedule).
 */
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Service $service): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function update(User $user, Service $service): bool
    {
        return $user->isManagerOrOwner();
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->isManagerOrOwner();
    }
}
