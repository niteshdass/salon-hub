<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'customer_account_id',
        'name',
        'phone',
        'email',
        'notes',
    ];

    /**
     * Always store email lowercase. The identity-linking queries in
     * Customer\AuthController::verifyCode and Public\BookingController::book
     * compare Customer.email against an already-lowercased value with `=`,
     * which is case-sensitive on sqlite — normalizing at write time keeps a
     * booking made with mixed-case input linkable to its account.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value !== null ? strtolower($value) : null,
        );
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
