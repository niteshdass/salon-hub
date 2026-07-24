<?php

namespace App\Policies;

use App\Models\User;

/**
 * Reminder settings hold third-party messaging credentials and drive
 * outbound messages billed to the salon — owner only, including reads
 * (which expose channel/lead-time configuration, never the secrets).
 *
 * There is one row per org and it is resolved from the bound tenant, not
 * from the route, so these abilities are checked at class level.
 */
class ReminderSettingPolicy
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
