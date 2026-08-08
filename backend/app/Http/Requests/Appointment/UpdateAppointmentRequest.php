<?php

namespace App\Http\Requests\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    /**
     * Two gates: who may touch this row (policy — staff are limited to
     * their own schedule), and what they may change. A staff member may
     * only move an appointment through its statuses; rescheduling,
     * reassigning or re-pricing stays with owner/manager.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user->can('update', $this->route('appointment'))) {
            return false;
        }

        return ! $user->isStaff() || array_diff(array_keys($this->all()), ['status']) === [];
    }

    /**
     * Partial reschedule / status change. Every field is optional, but
     * when present it must still resolve within the current tenant.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'staff_id' => [
                'sometimes',
                'required',
                Rule::exists('users', 'id')
                    ->where('organization_id', $tenantId)
                    ->where('role', UserRole::STAFF->value),
            ],
            // The services on this visit, in the order the customer picked
            // them. Each must belong to this salon; a bare id is never trusted.
            'service_ids' => ['sometimes', 'array', 'min:1'],
            'service_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('services', 'id')->where('organization_id', $tenantId),
            ],
            'customer_id' => [
                'sometimes',
                'required',
                Rule::exists('customers', 'id')->where('organization_id', $tenantId),
            ],
            'booking_date' => ['sometimes', 'required', 'date', 'after_or_equal:today'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i,H:i:s'],
            'status' => ['sometimes', 'required', Rule::enum(AppointmentStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
