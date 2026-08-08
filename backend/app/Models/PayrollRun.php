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
