<?php

namespace App\Policies;

use App\Models\Gallery;
use App\Models\User;

/**
 * Gallery images are public marketing content: everyone in the salon can
 * see what is published, owner and manager curate it.
 */
class GalleryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Gallery $gallery): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function update(User $user, Gallery $gallery): bool
    {
        return $user->isManagerOrOwner();
    }

    public function delete(User $user, Gallery $gallery): bool
    {
        return $user->isManagerOrOwner();
    }
}
