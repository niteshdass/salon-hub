<?php

namespace App\Http\Controllers;

use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;

/**
 * The invoice for a single booking: every service line snapshotted at
 * booking time, every payment taken, and the resulting balance. Nothing is
 * stored — it is computed on read from the appointment and its payments, so
 * it always reflects the current payment state.
 */
class InvoiceController extends Controller
{
    public function __invoke(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        $appointment->load(['customer', 'lines', 'organization', 'payments.recorder']);

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

            // One row per booked service; a visit with no lines (shouldn't
            // happen for a real booking) still shows a single fallback row
            // against the appointment's own quoted total.
            'line_items' => $appointment->lines->isNotEmpty()
                ? $appointment->lines->map(fn ($line) => [
                    'description' => $line->name,
                    'amount' => $line->price,
                ])->all()
                : [[
                    'description' => 'Service',
                    'amount' => $appointment->price,
                ]],
            'subtotal' => $appointment->price,
            'amount_paid' => $appointment->amountPaid(),
            'amount_pending' => $appointment->amountPending(),
            'balance_due' => $appointment->balanceDue(),
            'paid_in_full' => (float) $appointment->balanceDue() <= 0,

            'payments' => PaymentResource::collection($appointment->payments),
        ]]);
    }
}
