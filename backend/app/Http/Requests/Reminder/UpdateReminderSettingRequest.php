<?php

namespace App\Http\Requests\Reminder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReminderSettingRequest extends FormRequest
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
        return [
            'enabled' => ['required', 'boolean'],
            'channel' => ['required', Rule::in(['whatsapp', 'sms'])],
            'lead_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'credentials' => ['nullable', 'array'],
            'credentials.phone_number_id' => ['nullable', 'string', 'max:255'],
            'credentials.access_token' => ['nullable', 'string', 'max:1024'],
            'credentials.template_name' => ['nullable', 'string', 'max:255'],
            'credentials.provider' => ['nullable', 'string', 'max:255'],
            'credentials.from' => ['nullable', 'string', 'max:255'],
            'credentials.api_key' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
