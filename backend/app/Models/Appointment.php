<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Appointment extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'public_token',
        'branch_id',
        'customer_id',
        'staff_id',
        'service_id',
        'booking_date',
        'start_time',
        'end_time',
        'price',
        'status',
        'reminder_sent_at',
        'notes',
    ];

    /**
     * Assign a public manage token on creation when one was not supplied.
     */
    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment): void {
            if (empty($appointment->public_token)) {
                $appointment->public_token = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'price' => 'decimal:2',
            'status' => AppointmentStatus::class,
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Total collected against this booking. */
    public function amountPaid(): string
    {
        return number_format((float) $this->payments->sum('amount'), 2, '.', '');
    }

    /** Outstanding balance: quoted price less what has been collected. */
    public function balanceDue(): string
    {
        return number_format((float) $this->price - (float) $this->amountPaid(), 2, '.', '');
    }
}
