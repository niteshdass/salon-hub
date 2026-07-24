<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Only the shape is validated — whether the address has an account
        // is deliberately not revealed.
        return [
            'email' => ['required', 'email'],
        ];
    }
}
