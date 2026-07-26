<?php

namespace App\Http\Requests\StaffTimeOff;

use App\Models\StaffTimeOff;
use Illuminate\Foundation\Http\FormRequest;

class StoreStaffTimeOffRequest extends FormRequest
{
    /**
     * Role-gated here so a front-desk account gets a 403 before the staff
     * lookup or validation can turn it into a 404 / 422.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', StaffTimeOff::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
