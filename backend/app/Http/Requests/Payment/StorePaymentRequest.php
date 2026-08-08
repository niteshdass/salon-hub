<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Payment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // gte:0 rather than gt:0 so a customer who has already settled can
            // still leave a tip; the withValidator rule below stops a payment
            // that moves no money at all.
            'amount' => ['required', 'numeric', 'gte:0', 'max:99999999.99'],
            'tip_amount' => ['nullable', 'numeric', 'gte:0', 'max:99999999.99'],
            'method' => ['required', new Enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ((float) $this->input('amount', 0) <= 0 && (float) $this->input('tip_amount', 0) <= 0) {
                $validator->errors()->add('amount', 'Enter an amount, a tip, or both.');
            }
        });
    }
}
