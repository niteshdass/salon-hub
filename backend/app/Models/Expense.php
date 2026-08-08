<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'payroll_run_id',
        'recorded_by',
        'category',
        'expense_date',
        'amount',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'expense_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** Written by a finalized payroll run, so not editable on its own. */
    public function isSystemGenerated(): bool
    {
        return $this->payroll_run_id !== null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
