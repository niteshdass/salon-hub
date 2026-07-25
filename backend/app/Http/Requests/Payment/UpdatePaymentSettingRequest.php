<?php

namespace App\Http\Requests\Payment;

use App\Enums\DepositType;
use App\Models\PaymentSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdatePaymentSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', PaymentSetting::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isPercent = $this->input('deposit_type') === DepositType::PERCENT->value;
        $requiresValue = $this->input('deposit_type') !== DepositType::NONE->value;

        return [
            'deposit_type' => ['required', new Enum(DepositType::class)],
            // A deposit that is not "none" must set a value > 0; a percentage
            // is additionally capped at 100.
            'deposit_value' => [
                Rule::requiredIf($requiresValue),
                'numeric',
                $requiresValue ? 'gt:0' : 'gte:0',
                $isPercent ? 'max:100' : 'max:99999999.99',
            ],

            // Manual transfer needs somewhere for the money to go before it
            // can be offered to a customer.
            'manual_enabled' => ['required', 'boolean'],
            'manual_account_number' => [
                Rule::requiredIf((bool) $this->input('manual_enabled')),
                'nullable', 'string', 'max:255',
            ],
            'manual_instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
