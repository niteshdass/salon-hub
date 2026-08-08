<?php

namespace App\Models;

use App\Enums\PayType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No tenant scope of its own: a line is only ever reached through its run,
 * which is scoped, and it carries no organization_id.
 */
class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_run_id',
        'staff_id',
        'staff_name',
        'pay_type',
        'commission_rate',
        'monthly_salary',
        'earned_revenue',
        'bookings',
        'salary_amount',
        'commission_amount',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'pay_type' => PayType::class,
            'commission_rate' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'earned_revenue' => 'decimal:2',
            'salary_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'bookings' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
