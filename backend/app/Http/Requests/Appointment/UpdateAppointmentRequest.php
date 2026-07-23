<?php

namespace App\Http\Requests\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'service_id' => [
                'sometimes',
                'required',
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
