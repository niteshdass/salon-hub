<?php

namespace App\Http\Requests\Reminder;

use App\Models\ReminderSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReminderSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', ReminderSetting::class);
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
            // Twilio carries both channels: the same account, a different
            // sender and an address scheme for WhatsApp.
            'credentials' => ['nullable', 'array'],
            'credentials.account_sid' => ['nullable', 'string', 'max:255'],
            'credentials.auth_token' => ['nullable', 'string', 'max:1024'],
            'credentials.from' => ['nullable', 'string', 'max:255'],
            'credentials.whatsapp_from' => ['nullable', 'string', 'max:255'],
            'credentials.messaging_service_sid' => ['nullable', 'string', 'max:255'],
        ];
    }
}
