<?php

namespace App\Http\Requests\Public;

use App\Enums\UserRole;
use App\Models\CustomerAccount;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a public (no-auth) booking. Everything must belong to the
 * organization bound by ResolvePublicTenant, so the exists() rules are all
 * constrained to the current tenant id.
 */
class PublicBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint: authorization is enforced by tenant scoping, not auth.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            // The services on this visit, in the order the customer picked
            // them. Each must belong to this salon; a bare id is never trusted.
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('services', 'id')->where('organization_id', $tenantId),
            ],
            'staff_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->where('organization_id', $tenantId)
                    ->where('role', UserRole::STAFF->value),
            ],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where('organization_id', $tenantId),
            ],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'customer' => ['required', 'array'],
            // A signed-in booker never re-types their identity: the account
            // supplies the name, so the wizard drops the field. Anonymous
            // bookings still have to carry one — nobody else can supply it.
            'customer.name' => [
                $this->bookingAccount()?->name ? 'nullable' : 'required',
                'string',
                'max:255',
            ],
            'customer.phone' => ['required', 'string', 'max:50'],
            // Ignored when signed in: the account's own address wins there.
            'customer.email' => ['nullable', 'email', 'max:255'],

            // How the deposit is paid: 'manual' (bank/wallet transfer, needs a
            // reference) or 'gateway' (SSLCommerz online). Which methods are on
            // offer, and whether a choice is required, is enforced in the
            // controller against the salon's deposit policy.
            'payment_method' => ['nullable', Rule::in(['manual', 'gateway'])],

            // Manual-transfer deposit reference (the transaction number the
            // customer paid with). Whether it is *required* depends on the
            // salon's deposit policy, enforced in the controller.
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** The customer account booking this, when the visitor is signed in. */
    protected function bookingAccount(): ?CustomerAccount
    {
        return CustomerAccount::current();
    }
}
