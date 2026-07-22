<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'designation',
        'bio',
        'profile_image',
        'working_days_json',
        'working_hours_json',
    ];

    protected function casts(): array
    {
        return [
            'working_days_json' => 'array',
            'working_hours_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
