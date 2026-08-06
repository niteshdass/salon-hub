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
            //
            // It must therefore be a valid DNS label, not merely a URL
            // segment. `alpha_dash` was the wrong rule: it permits `_` and
            // leading/trailing `-`, and `beauty_queen` or `-lead-` matches
            // neither nginx-salon.conf's `~^(?<slug>[a-z0-9-]+)\.` server_name
            // nor frontend/src/lib/tenantHost.js's `/^[a-z0-9-]+$/`. The
            // salon's own subdomain would fall through to the default server
            // and the SPA would render the marketing landing page on it, with
            // no error anywhere. `max:63` is the DNS label limit; 255 is the
            // limit for a whole hostname.
            'slug' => [
                'nullable',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                'max:63',
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
            'slug.regex' => 'Your address may use only lowercase letters, numbers and hyphens, and must start and end with a letter or number.',
            'slug.max' => 'Your address may be at most 63 characters.',
        ];
    }
}
