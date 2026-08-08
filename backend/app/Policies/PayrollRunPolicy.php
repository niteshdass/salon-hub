<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

/**
 * Payroll is every colleague's salary in one table — owner-only, reads
 * included. Runs are tenant-scoped by the model, so these rules depend on
 * the actor's role alone.
 */
class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, PayrollRun $run): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, PayrollRun $run): bool
    {
        return $user->isOwner();
    }
}
