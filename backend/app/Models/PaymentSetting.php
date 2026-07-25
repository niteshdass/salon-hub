<?php

namespace App\Models;

use App\Enums\DepositType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSetting extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'deposit_type',
        'deposit_value',
        'manual_enabled',
        'manual_account_number',
        'manual_instructions',
        'gateway',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'deposit_type' => DepositType::class,
            'deposit_value' => 'decimal:2',
            'manual_enabled' => 'boolean',
            'credentials' => 'encrypted:array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The deposit required to book a service at the given price, in the
     * salon's currency. Percentages round to cents; a fixed deposit never
     * exceeds the price itself.
     */
    public function depositFor(float $price): string
    {
        $amount = match ($this->deposit_type) {
            DepositType::PERCENT => $price * ((float) $this->deposit_value / 100),
            DepositType::FIXED => min((float) $this->deposit_value, $price),
            default => 0.0,
        };

        return number_format(round($amount, 2), 2, '.', '');
    }

    /** Whether a customer must pay something before this booking is taken. */
    public function requiresDeposit(): bool
    {
        return $this->deposit_type !== DepositType::NONE
            && (float) $this->deposit_value > 0;
    }
}
