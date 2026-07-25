<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Anyone on the team can see and take a payment at checkout; only an owner
 * or manager can delete a recorded payment, since removing a money record
 * is a correction that should not sit with front-desk staff.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->isManagerOrOwner();
    }

    /**
     * Confirming a customer's submitted deposit releases the booking's
     * balance — a money decision reserved for an owner or manager.
     */
    public function verify(User $user, Payment $payment): bool
    {
        return $user->isManagerOrOwner();
    }

    /**
     * Returning a captured online deposit to the customer — like deleting a
     * money record, this is an owner/manager decision, not a front-desk one.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->isManagerOrOwner();
    }
}
