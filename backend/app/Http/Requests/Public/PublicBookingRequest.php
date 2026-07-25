<?php

namespace App\Http\Requests\Public;

use App\Enums\UserRole;
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
            'service_id' => [
                'required',
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
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:50'],
            'customer.email' => ['nullable', 'email', 'max:255'],

            // Manual-transfer deposit reference (the transaction number the
            // customer paid with). Whether it is *required* depends on the
            // salon's deposit policy, enforced in the controller.
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
