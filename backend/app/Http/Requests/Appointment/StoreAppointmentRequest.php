<?php

namespace App\Http\Requests\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Appointment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'branch_id' => [
                'required',
                Rule::exists('branches', 'id')->where('organization_id', $tenantId),
            ],
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
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'customer_id' => [
                'nullable',
                'required_without:new_customer',
                Rule::exists('customers', 'id')->where('organization_id', $tenantId),
            ],
            'new_customer' => ['nullable', 'required_without:customer_id', 'array'],
            'new_customer.name' => ['required_without:customer_id', 'string', 'max:255'],
            'new_customer.phone' => ['nullable', 'string', 'max:50'],
            'new_customer.email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
