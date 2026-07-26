<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off block of unavailability for a staff member, as a datetime range.
 * Covers a full day, a multi-day vacation, a half-day, or a short break — the
 * slot engine drops any candidate whose service window overlaps the range.
 */
class StaffTimeOff extends Model
{
    use BelongsToOrganization;

    protected $table = 'staff_time_off';

    protected $fillable = [
        'organization_id',
        'user_id',
        'start_at',
        'end_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
