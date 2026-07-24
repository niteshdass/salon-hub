<?php

namespace App\Http\Requests\Staff;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
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
        ];
    }
}
