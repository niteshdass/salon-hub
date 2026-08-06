<?php

namespace App\Services;

use App\Models\PaymentSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for SSLCommerz's hosted checkout. Two calls matter:
 *
 *  - initiate(): open a payment session and get the URL to send the customer to.
 *  - validate(): after the customer returns, confirm the transaction is real
 *    and paid — never trust the browser-posted callback fields alone.
 *
 * Credentials come from the per-org (encrypted) settings; nothing is hardcoded.
 */
class SslcommerzGateway
{
    private const SANDBOX = 'https://sandbox.sslcommerz.com';

    private const LIVE = 'https://securepay.sslcommerz.com';

    public function baseUrl(PaymentSetting $settings): string
    {
        return $settings->gateway_sandbox ? self::SANDBOX : self::LIVE;
    }

    /**
     * Open a hosted payment session and return the GatewayPageURL to redirect
     * the customer to. Throws if SSLCommerz declines to create the session.
     *
     * @param  array<string, mixed>  $order  total_amount, currency, tran_id,
     *                                       success_url, fail_url, cancel_url,
     *                                       cus_name, cus_email, cus_phone, …
     */
    public function initiate(PaymentSetting $settings, array $order): string
    {
        $creds = $settings->credentials ?? [];

        $response = Http::asForm()->post(
            $this->baseUrl($settings).'/gwprocess/v4/api.php',
            array_merge([
                'store_id' => $creds['store_id'] ?? '',
                'store_passwd' => $creds['store_passwd'] ?? '',
                'shipping_method' => 'NO',
                'product_name' => 'Booking deposit',
                'product_category' => 'service',
                'product_profile' => 'general',
            ], $order),
        );

        $body = $response->json() ?? [];
        $url = $body['GatewayPageURL'] ?? null;

        if (strtoupper((string) ($body['status'] ?? '')) !== 'SUCCESS' || blank($url)) {
            throw new RuntimeException(
                'SSLCommerz session could not be created: '.($body['failedreason'] ?? 'unknown error'),
            );
        }

        return $url;
    }

    /**
     * Server-to-server validation of a completed transaction by its val_id.
     *
     * @return array<string, mixed> the decoded validation response
     */
    public function validate(PaymentSetting $settings, string $valId): array
    {
        $creds = $settings->credentials ?? [];

        $response = Http::get($this->baseUrl($settings).'/validator/api/validationserverAPI.php', [
            'val_id' => $valId,
            'store_id' => $creds['store_id'] ?? '',
            'store_passwd' => $creds['store_passwd'] ?? '',
            'format' => 'json',
        ]);

        return $response->json() ?? [];
    }

    /**
     * Request a refund of a captured transaction by its bank transaction id.
     * Refunds are processed asynchronously by SSLCommerz; the response reports
     * whether the request was accepted (status success / processing) and a
     * refund_ref_id to track it.
     *
     * @return array<string, mixed> the decoded refund response
     */
    public function refund(PaymentSetting $settings, string $bankTranId, string $amount, string $remarks): array
    {
        $creds = $settings->credentials ?? [];

        $response = Http::get($this->baseUrl($settings).'/validator/api/merchantTransIDvalidationAPI.php', [
            'bank_tran_id' => $bankTranId,
            'refund_amount' => $amount,
            'refund_remarks' => $remarks,
            'store_id' => $creds['store_id'] ?? '',
            'store_passwd' => $creds['store_passwd'] ?? '',
            'format' => 'json',
        ]);

        return $response->json() ?? [];
    }
}
