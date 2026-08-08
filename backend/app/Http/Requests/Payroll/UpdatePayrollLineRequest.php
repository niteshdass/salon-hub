<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayrollLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('run'));
    }

    /**
     * Only the two payable amounts are editable. earned_revenue and bookings
     * are computed facts, not opinions.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'salary_amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'commission_amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
