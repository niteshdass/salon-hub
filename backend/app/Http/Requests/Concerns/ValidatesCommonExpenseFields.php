<?php

namespace App\Http\Requests\Concerns;

use App\Tenancy\CurrentTenant;
use Illuminate\Validation\Rule;

/**
 * `note` and `branch_id` validate identically whether an expense is being
 * created or updated — unlike `category`/`expense_date`/`amount`, neither
 * needs a `required` vs `sometimes|required` split, so both requests share
 * these rules from one place instead of repeating them.
 */
trait ValidatesCommonExpenseFields
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function commonExpenseRules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where('organization_id', app(CurrentTenant::class)->id()),
            ],
        ];
    }
}
