<?php

namespace App\Policies;

use App\Models\User;

/**
 * The salon's own profile — name, contact details, branding, social
 * links. Owner only, in line with every other org-level setting.
 *
 * There is one organization per tenant and it comes from the bound
 * tenant rather than the route, so these abilities are checked at class
 * level.
 */
class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user): bool
    {
        return $user->isOwner();
    }
}
