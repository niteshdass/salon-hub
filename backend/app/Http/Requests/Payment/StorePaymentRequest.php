<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use App\Models\Appointment;
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
            // A required/numeric failure on amount already explains the
            // problem; don't stack an unrelated message on the same key.
            if ($validator->errors()->has('amount')) {
                return;
            }

            $amount = (float) $this->input('amount', 0);
            $tip = (float) $this->input('tip_amount', 0);

            if ($amount <= 0 && $tip <= 0) {
                $validator->errors()->add('amount', 'Enter an amount, a tip, or both.');

                return;
            }

            // The amount settles the booking's own balance and must never
            // overshoot it. A zero amount settles nothing — it's a tip-only
            // payment — so it never needs capping, which matters once the
            // balance itself has gone negative (an already-overpaid booking,
            // e.g. a deposit verified after the counter already collected in
            // full): 0 is not "over" a negative balance in any sense a
            // counter operator would recognise. The tip is never capped by
            // anything.
            $appointment = $this->route('appointment');
            if ($appointment instanceof Appointment && $amount > 0) {
                $appointment->loadMissing('payments');
                $balance = (float) $appointment->balanceDue();

                if ($amount > $balance) {
                    $validator->errors()->add(
                        'amount',
                        'Amount cannot exceed the remaining balance of '.number_format(max($balance, 0.0), 2, '.', '').'.'
                    );
                }
            }
        });
    }
}
