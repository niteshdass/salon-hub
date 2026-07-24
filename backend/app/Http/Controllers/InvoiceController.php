<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;

/**
 * The invoice for a single booking: the service line snapshotted at booking
 * time, every payment taken, and the resulting balance. Nothing is stored —
 * it is computed on read from the appointment and its payments, so it always
 * reflects the current payment state.
 */
class InvoiceController extends Controller
{
    public function __invoke(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        $appointment->load(['customer', 'service', 'organization', 'payments.recorder']);

        return response()->json(['data' => [
            'number' => 'INV-'.str_pad((string) $appointment->id, 6, '0', STR_PAD_LEFT),
            'issued_on' => optional($appointment->booking_date)->format('Y-m-d'),
            'currency' => $appointment->organization?->currency ?? 'USD',

            'salon' => [
                'name' => $appointment->organization?->name,
                'email' => $appointment->organization?->email,
                'phone' => $appointment->organization?->phone,
            ],
            'customer' => [
                'name' => $appointment->customer?->name,
                'phone' => $appointment->customer?->phone,
                'email' => $appointment->customer?->email,
            ],

            'line_items' => [[
                'description' => $appointment->service?->name ?? 'Service',
                'amount' => $appointment->price,
            ]],
            'subtotal' => $appointment->price,
            'amount_paid' => $appointment->amountPaid(),
            'balance_due' => $appointment->balanceDue(),
            'paid_in_full' => (float) $appointment->balanceDue() <= 0,

            'payments' => PaymentResource::collection($appointment->payments),
        ]]);
    }
}
