<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'period_month',
        'status',
        'total_salary',
        'total_commission',
        'total_amount',
        'finalized_at',
        'finalized_by',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'status' => PayrollRunStatus::class,
            'total_salary' => 'decimal:2',
            'total_commission' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'finalized_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === PayrollRunStatus::DRAFT;
    }

    /**
     * Recompute this run's totals from its lines. Called on create and again
     * after every line edit, so the header always matches the rows under it.
     * It lives here rather than on a controller because both the run and the
     * line endpoints need it, and a controller must never call a controller.
     */
    public function syncTotals(): void
    {
        $lines = $this->lines()->get();

        $this->update([
            'total_salary' => round((float) $lines->sum('salary_amount'), 2),
            'total_commission' => round((float) $lines->sum('commission_amount'), 2),
            'total_amount' => round((float) $lines->sum('total_amount'), 2),
        ]);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /** The salary expense this run wrote when it was finalized. */
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
