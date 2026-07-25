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
        'gateway_sandbox',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'deposit_type' => DepositType::class,
            'deposit_value' => 'decimal:2',
            'manual_enabled' => 'boolean',
            'gateway_sandbox' => 'boolean',
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

    /**
     * Whether the online gateway is fully configured (provider selected and
     * both store credentials on file), so it can actually charge a card.
     */
    public function gatewayEnabled(): bool
    {
        return $this->gateway === 'sslcommerz'
            && filled($this->credentials['store_id'] ?? null)
            && filled($this->credentials['store_passwd'] ?? null);
    }

    /**
     * Whether a required deposit can actually be collected: the salon wants a
     * deposit AND has at least one working way to take it.
     */
    public function depositCollectable(): bool
    {
        return $this->requiresDeposit()
            && ((bool) $this->manual_enabled || $this->gatewayEnabled());
    }
}
