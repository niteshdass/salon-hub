<?php

namespace App\Policies;

use App\Models\User;

/**
 * Payment settings decide what money the salon collects and hold gateway
 * credentials — owner only, reads included (a read exposes account details
 * and, later, the presence of gateway secrets). One row per org, resolved
 * from the bound tenant, so abilities are checked at class level.
 */
class PaymentSettingPolicy
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
