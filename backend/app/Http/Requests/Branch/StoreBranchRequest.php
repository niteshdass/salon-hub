<?php

namespace App\Http\Requests\Branch;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    /**
     * Authorized here rather than in the controller so a forbidden role
     * gets a 403 before validation (or the plan-limit check) can turn it
     * into a 422.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Branch::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'opening_hours_json' => ['nullable', 'array'],
            // Each weekday is either null (closed) or a [open, close] H:i pair.
            'opening_hours_json.*' => ['nullable', 'array', 'size:2'],
            'opening_hours_json.*.*' => ['date_format:H:i'],
        ];
    }
}
