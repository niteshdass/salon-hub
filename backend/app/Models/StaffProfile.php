<?php

namespace App\Models;

use App\Enums\PayType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'designation',
        'bio',
        'profile_image',
        'working_days_json',
        'working_hours_json',
        'pay_type',
        'monthly_salary',
        'commission_rate',
    ];

    protected function casts(): array
    {
        return [
            'working_days_json' => 'array',
            'working_hours_json' => 'array',
            'pay_type' => PayType::class,
            'monthly_salary' => 'decimal:2',
            'commission_rate' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
