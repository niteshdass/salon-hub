<?php

namespace App\Http\Requests\Settings;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Organization::class);
    }

    /**
     * The slug is deliberately absent: it is the public booking URL and
     * the tenant key, so it is not editable here.
     *
     * Every field is optional-if-absent: the controller only writes the
     * keys it is given, so a screen that owns part of the profile (the
     * onboarding branding step, which has no name field on it) can send
     * just its part. 'name' keeps 'required' for when it *is* sent, so the
     * full profile form still cannot blank it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'currency' => ['nullable', 'string', 'size:3'],

            'theme_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'about' => ['nullable', 'string', 'max:5000'],
            'facebook' => ['nullable', 'url', 'max:255'],
            'instagram' => ['nullable', 'url', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'theme_color.regex' => 'The theme color must be a hex value like #6366f1.',
            'about.max' => 'Your salon story must be 5000 characters or fewer.',
        ];
    }
}
