<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Staff need customer details for the appointments they serve, so reads
 * stay open to the whole org; the customer book itself is edited only by
 * owner and manager.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->isManagerOrOwner();
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->isManagerOrOwner();
    }
}
