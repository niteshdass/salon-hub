<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /** The services on this visit, in the order the customer picked them. */
    public function lines(): HasMany
    {
        return $this->hasMany(AppointmentService::class)->orderBy('sort_order');
    }

    /** The menu services behind those lines, for reporting reads. */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'appointment_services')
            ->withPivot(['name', 'price', 'duration', 'sort_order']);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /** Total confirmed money against this booking — verified payments only. */
    public function amountPaid(): string
    {
        $sum = $this->payments
            ->where('status', PaymentStatus::VERIFIED)
            ->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    /** Money submitted but not yet confirmed by the salon (awaiting verification). */
    public function amountPending(): string
    {
        $sum = $this->payments
            ->where('status', PaymentStatus::PENDING)
            ->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    /** Outstanding balance: quoted price less what has been confirmed collected. */
    public function balanceDue(): string
    {
        return number_format((float) $this->price - (float) $this->amountPaid(), 2, '.', '');
    }

    /** Still customer-editable (pending or confirmed). */
    public function isChangeable(): bool
    {
        return in_array($this->status, [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED], true);
    }

    /** A finished visit — the only state a customer may review. */
    public function isCompleted(): bool
    {
        return $this->status === AppointmentStatus::COMPLETED;
    }
}
