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

    /**
     * What a line is worth, defined in exactly one place: salary plus
     * commission. PayrollCalculator builds a fresh line through this, and a
     * manual amount edit re-runs it, so the two can never disagree.
     */
    public static function totalFor(mixed $salary, mixed $commission): float
    {
        return round((float) $salary + (float) $commission, 2);
    }

    public function recomputeTotal(): static
    {
        $this->total_amount = static::totalFor($this->salary_amount, $this->commission_amount);

        return $this;
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
