<?php

namespace App\Policies;

use App\Models\StaffTimeOff;
use App\Models\User;

/**
 * Managing a staff member's availability is a scheduling decision reserved
 * for an owner or manager; front-desk staff can view it but not change it.
 * The controller resolves the staff + row within the tenant, so these rules
 * depend on the actor's role alone.
 */
class StaffTimeOffPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function delete(User $user, StaffTimeOff $timeOff): bool
    {
        return $user->isManagerOrOwner();
    }
}
