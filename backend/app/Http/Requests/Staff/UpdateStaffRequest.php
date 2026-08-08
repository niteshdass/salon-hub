<?php

namespace App\Http\Requests\Staff;

use App\Enums\PayType;
use App\Http\Requests\Concerns\StripsOwnerOnlyFields;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    use StripsOwnerOnlyFields;

    /**
     * Class-level check: the staff row is resolved manually inside the
     * controller (User has no tenant global scope), and the rule depends
     * on the actor's role only.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', User::class);
    }

    /**
     * @return array<int, string>
     */
    protected function ownerOnlyFields(): array
    {
        return ['pay_type', 'monthly_salary', 'commission_rate'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('staff')),
            ],
            'phone' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'designation' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'profile_image' => ['nullable', 'string', 'max:2048'],
            'working_days_json' => ['nullable', 'array'],
            'working_days_json.*' => ['integer', 'between:1,7'],
            'working_hours_json' => ['nullable', 'array'],
            'working_hours_json.start' => ['required_with:working_hours_json.end', 'date_format:H:i'],
            'working_hours_json.end' => ['required_with:working_hours_json.start', 'date_format:H:i'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => [
                Rule::exists('services', 'id')->where('organization_id', $tenantId),
            ],
            'pay_type' => ['nullable', Rule::enum(PayType::class)],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
