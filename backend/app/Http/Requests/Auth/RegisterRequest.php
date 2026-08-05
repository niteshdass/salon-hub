<?php

namespace App\Http\Requests\Auth;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Lowercase the slug before it is validated. Str::slug() lowercases it
     * downstream anyway, so this only makes the rules below judge the same
     * string that will actually be stored and minted as a host — "App" is the
     * reserved "app", and must be told so rather than quietly becoming
     * something else.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'salon_name' => ['required', 'string', 'max:255'],
            // The slug becomes <slug>.APP_DOMAIN, a verified host that selects
            // the tenant — so the platform's own hostnames are not claimable.
            // Compared case-insensitively because the host it mints is
            // lowercased either way.
            'slug' => [
                'nullable',
                'alpha_dash',
                'max:255',
                Rule::notIn(Organization::RESERVED_SLUGS),
                'unique:organizations,slug',
            ],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.not_in' => 'That address is reserved. Please choose another.',
        ];
    }
}
