<?php

namespace App\Http\Controllers;

use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Services\SslcommerzGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Payments collected against a booking. Both the appointment and its
 * payments are auto-scoped by BelongsToOrganization, so route-model binding
 * cannot reach another tenant's records (a foreign id 404s). The payment is
 * additionally bound within the appointment so a valid id under the wrong
 * booking 404s rather than acting on the wrong row.
 */
class PaymentController extends Controller
{
    public function index(Appointment $appointment): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Payment::class);

        $payments = $appointment->payments()
            ->with('recorder')
            ->latest('id')
            ->get();

        return PaymentResource::collection($payments);
    }

    public function store(StorePaymentRequest $request, Appointment $appointment): JsonResponse
    {
        // Taken at the counter by a team member: confirmed money, not a
        // customer-submitted deposit awaiting verification.
        $payment = $appointment->payments()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
            'status' => PaymentStatus::VERIFIED,
            'source' => PaymentSource::STAFF,
        ]);

        return (new PaymentResource($payment->load('recorder')))
            ->response()->setStatusCode(201);
    }

    /**
     * Confirm a customer-submitted deposit (e.g. a manual transfer reference)
     * once the owner has checked the money arrived. Only then does it count
     * toward the booking's balance.
     */
    public function verify(Appointment $appointment, Payment $payment): JsonResponse
    {
        abort_unless($payment->appointment_id === $appointment->id, 404);

        $this->authorize('verify', $payment);

        $payment->update(['status' => PaymentStatus::VERIFIED]);

        return (new PaymentResource($payment->load('recorder')))->response();
    }

    /**
     * Return a captured online deposit to the customer via the gateway. Only a
     * verified gateway payment (with the bank_tran_id needed to address the
     * refund) qualifies; counter and manual payments are settled off-platform.
     * The deposit is marked refunded only once SSLCommerz accepts the request.
     */
    public function refund(Appointment $appointment, Payment $payment, SslcommerzGateway $gateway): JsonResponse
    {
        abort_unless($payment->appointment_id === $appointment->id, 404);

        $this->authorize('refund', $payment);

        if ($payment->source !== PaymentSource::GATEWAY
            || $payment->status !== PaymentStatus::VERIFIED
            || blank($payment->bank_tran_id)) {
            return response()->json(['message' => 'This payment cannot be refunded.'], 422);
        }

        $settings = PaymentSetting::query()->first();
        if (! $settings || ! $settings->gatewayEnabled()) {
            return response()->json(['message' => 'The online gateway is not configured.'], 422);
        }

        $result = $gateway->refund(
            $settings,
            $payment->bank_tran_id,
            (string) $payment->amount,
            'Refund for booking #'.$appointment->id,
        );

        // SSLCommerz processes refunds asynchronously; success/processing means
        // the request was accepted. Anything else leaves the money on the books.
        $status = strtolower((string) ($result['status'] ?? ''));
        if (! in_array($status, ['success', 'processing', 'initiated'], true)) {
            return response()->json([
                'message' => $result['errorReason'] ?? 'The gateway declined the refund.',
            ], 422);
        }

        $payment->update([
            'status' => PaymentStatus::REFUNDED,
            'refund_ref' => $result['refund_ref_id'] ?? null,
            'refunded_at' => now(),
        ]);

        return (new PaymentResource($payment->fresh()->load('recorder')))->response();
    }

    public function destroy(Appointment $appointment, Payment $payment): Response
    {
        // 404 (not 403) when the payment belongs to a different booking, so a
        // guessed id under the wrong appointment leaks nothing.
        abort_unless($payment->appointment_id === $appointment->id, 404);

        $this->authorize('delete', $payment);

        $payment->delete();

        return response()->noContent();
    }
}
