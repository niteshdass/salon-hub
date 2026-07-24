<?php

namespace App\Http\Requests\Service;

use App\Enums\ServiceStatus;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('service'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                Rule::exists('service_categories', 'id')->where('organization_id', $tenantId),
            ],
            'description' => ['nullable', 'string'],
            'duration' => ['sometimes', 'required', 'integer', 'min:1'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in([ServiceStatus::ACTIVE->value, ServiceStatus::INACTIVE->value])],
        ];
    }
}
