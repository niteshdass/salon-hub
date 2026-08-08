<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform-wide customer identity. Global — NOT tenant-scoped. One account
 * links to many per-salon `customers` rows via customer_account_id.
 */
class CustomerAccount extends Model
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'email_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * The account authenticating the current request, or null.
     *
     * Public booking routes carry no auth middleware — a visitor without an
     * account must still be able to book — so the guard is asked directly.
     * It resolves the bearer token on demand, and its `customers` provider
     * means a staff token or web session yields null rather than an account.
     */
    public static function current(): ?self
    {
        $account = Auth::guard('customer')->user();

        return $account instanceof self ? $account : null;
    }
}
