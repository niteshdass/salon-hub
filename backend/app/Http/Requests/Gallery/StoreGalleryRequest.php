<?php

namespace App\Http\Requests\Gallery;

use App\Models\Gallery;
use Illuminate\Foundation\Http\FormRequest;

class StoreGalleryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Gallery::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'max:4096'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
