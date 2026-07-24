<?php

namespace App\Http\Requests\Settings;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class UploadOrganizationImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Organization::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'max:4096'],
        ];
    }
}
