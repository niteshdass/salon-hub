<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * Any authed team member may read reviews (they inform the whole salon), but
 * hiding or deleting one is a moderation decision reserved for an owner or
 * manager. The tenant scope decides which reviews are visible at all.
 */
class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Review $review): bool
    {
        return $user->isManagerOrOwner();
    }

    public function delete(User $user, Review $review): bool
    {
        return $user->isManagerOrOwner();
    }
}
