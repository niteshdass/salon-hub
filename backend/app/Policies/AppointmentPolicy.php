<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

/**
 * Owner and manager run the whole book. A staff member only sees and
 * touches their own schedule — the index is additionally filtered to
 * their own rows in AppointmentController, since a policy cannot narrow
 * a collection.
 *
 * `update` here only decides WHO may touch the row; WHICH fields a staff
 * member may change (status only) is enforced in UpdateAppointmentRequest.
 */
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->isManagerOrOwner() || $appointment->staff_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isManagerOrOwner();
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isManagerOrOwner() || $appointment->staff_id === $user->id;
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->isManagerOrOwner();
    }
}
