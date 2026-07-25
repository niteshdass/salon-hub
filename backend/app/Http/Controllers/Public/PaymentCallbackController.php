<?php

namespace App\Http\Controllers\Public;

use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Services\SslcommerzGateway;
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
    public function __construct(protected SslcommerzGateway $gateway)
    {
    }

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

        $settings = PaymentSetting::query()->first();
        $valId = (string) $request->input('val_id', '');

        if ($settings && $valId !== '' && $this->isGenuinelyPaid($settings, $payment, $tran, $valId)) {
            if ($payment->status !== PaymentStatus::VERIFIED) {
                $payment->update(['status' => PaymentStatus::VERIFIED]);
            }

            return $this->backToSpa($org, $payment, 'success');
        }

        return $this->backToSpa($org, $payment, 'failed');
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
     * Confirm with SSLCommerz that the transaction is validated, matches this
     * payment's own id, and covers at least the deposit amount owed. Guards
     * against replayed / tampered browser callbacks.
     */
    protected function isGenuinelyPaid(PaymentSetting $settings, Payment $payment, string $tran, string $valId): bool
    {
        $result = $this->gateway->validate($settings, $valId);

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
