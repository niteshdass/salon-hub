<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * What the salon spends — including every salary — is owner-only, reads
 * included. A manager who can read the expense log can read the payroll.
 * Rows are tenant-scoped by the model, so these rules depend on role alone.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->isOwner();
    }
}
