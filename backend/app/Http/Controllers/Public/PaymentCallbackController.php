<?php

namespace App\Http\Controllers\Public;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Services\SslcommerzGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SSLCommerz browser callbacks. The gateway redirects the customer's browser
 * here (a POST) after checkout. The success path is never trusted on the
 * posted fields alone: the transaction is re-validated server-to-server before
 * the deposit is marked verified. Every path ends by sending the customer back
 * to the SPA's manage page with the outcome.
 *
 * Routed under public/{org} + public.tenant, so the payment lookup is already
 * scoped to the resolved organization.
 */
class PaymentCallbackController extends Controller
{
    public function __construct(protected SslcommerzGateway $gateway) {}

    /**
     * Payment succeeded at the gateway: validate it, and on a genuine, fully
     * paid transaction mark the deposit verified.
     */
    public function success(Request $request, string $org, string $tran): RedirectResponse
    {
        $payment = $this->findPayment($tran);
        if (! $payment) {
            return $this->backToSpa($org, null, 'error');
        }

        $captured = $this->capture($payment, $tran, (string) $request->input('val_id', ''));

        return $this->backToSpa($org, $payment, $captured ? 'success' : 'failed');
    }

    /**
     * SSLCommerz Instant Payment Notification: a server-to-server POST the
     * gateway sends directly, independent of the customer's browser. This is
     * the safety net for a payment captured after the customer closed the tab
     * — without it that deposit would sit pending forever. Same validation and
     * capture as the browser success path, but the reply is a plain 200 the
     * gateway can acknowledge, never a redirect. Always 200, even when the
     * transaction doesn't validate, so the gateway stops retrying.
     */
    public function ipn(Request $request, string $org, string $tran): JsonResponse
    {
        $payment = $this->findPayment($tran);

        if ($payment) {
            $this->capture($payment, $tran, (string) $request->input('val_id', ''));
        }

        return response()->json(['received' => true]);
    }

    /** Payment failed at the gateway: the deposit stays pending. */
    public function fail(string $org, string $tran): RedirectResponse
    {
        return $this->backToSpa($org, $this->findPayment($tran), 'failed');
    }

    /** Customer abandoned checkout: the deposit stays pending. */
    public function cancel(string $org, string $tran): RedirectResponse
    {
        return $this->backToSpa($org, $this->findPayment($tran), 'cancelled');
    }

    /**
     * Validate a returned transaction and, if genuine and fully paid, mark the
     * deposit verified and confirm the booking. Idempotent: re-running for an
     * already-verified payment is a no-op, so the browser callback and the IPN
     * (which may both fire, and may fire twice) never double-apply. Returns
     * whether the payment is captured.
     */
    protected function capture(Payment $payment, string $tran, string $valId): bool
    {
        $settings = PaymentSetting::query()->first();

        if (! $settings || $valId === '') {
            return false;
        }

        $result = $this->gateway->validate($settings, $valId);

        if (! $this->isGenuinelyPaid($result, $payment, $tran)) {
            return false;
        }

        // Mark verified once, and record the gateway's bank_tran_id the first
        // time we see it — it is what a later refund is issued against.
        $attrs = [];
        if ($payment->status !== PaymentStatus::VERIFIED) {
            $attrs['status'] = PaymentStatus::VERIFIED;
        }
        if (filled($result['bank_tran_id'] ?? null) && blank($payment->bank_tran_id)) {
            $attrs['bank_tran_id'] = $result['bank_tran_id'];
        }
        if ($attrs !== []) {
            $payment->update($attrs);
        }

        // A captured online deposit confirms the booking outright — no owner
        // review needed as there is with a manual transfer. Only a still-pending
        // booking is moved; a cancelled one is left alone.
        $appointment = $payment->appointment;
        if ($appointment && $appointment->status === AppointmentStatus::PENDING) {
            $appointment->update(['status' => AppointmentStatus::CONFIRMED]);
        }

        return true;
    }

    /**
     * A pending gateway payment for this transaction id (tenant-scoped). Null
     * for an unknown / already-consumed id.
     */
    protected function findPayment(string $tran): ?Payment
    {
        return Payment::where('transaction_id', $tran)
            ->where('source', PaymentSource::GATEWAY)
            ->with('appointment')
            ->first();
    }

    /**
     * Confirm a validation response reports a real transaction that matches
     * this payment's own id and covers at least the deposit amount owed.
     * Guards against replayed / tampered browser callbacks.
     *
     * @param  array<string, mixed>  $result  the gateway's validation response
     */
    protected function isGenuinelyPaid(array $result, Payment $payment, string $tran): bool
    {
        $status = strtoupper((string) ($result['status'] ?? ''));
        $tranMatches = (string) ($result['tran_id'] ?? '') === $tran;
        $amountCovered = isset($result['amount']) && (float) $result['amount'] >= (float) $payment->amount;

        return in_array($status, ['VALID', 'VALIDATED'], true) && $tranMatches && $amountCovered;
    }

    /**
     * Redirect the customer back to the SPA's booking-manage page with the
     * payment outcome. Falls back to the SPA root when no booking is known.
     */
    protected function backToSpa(string $org, ?Payment $payment, string $status): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $token = $payment?->appointment?->public_token;

        $target = $token
            ? $frontend.'/book/'.$org.'/manage/'.$token.'?payment='.$status
            : $frontend;

        return redirect()->away($target);
    }
}
