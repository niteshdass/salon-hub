<?php

namespace Tests\Unit\Services;

use App\Models\PaymentSetting;
use App\Services\SslcommerzGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The gateway must talk to the right SSLCommerz host for the mode the salon is
 * in: the sandbox while testing, the live gateway once the owner goes live.
 * Getting this wrong silently sends real customers to a test endpoint (or
 * test cards to the live one), so both directions are pinned.
 */
class SslcommerzGatewayTest extends TestCase
{
    private function settings(bool $sandbox): PaymentSetting
    {
        $settings = new PaymentSetting;
        $settings->gateway = 'sslcommerz';
        $settings->gateway_sandbox = $sandbox;
        $settings->credentials = ['store_id' => 'store', 'store_passwd' => 'pass'];

        return $settings;
    }

    public function test_live_mode_initiates_against_the_secure_pay_host(): void
    {
        Http::fake([
            'securepay.sslcommerz.com/gwprocess/*' => Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://securepay.sslcommerz.com/pay/x',
            ]),
        ]);

        $url = app(SslcommerzGateway::class)->initiate($this->settings(false), [
            'total_amount' => '10.00', 'tran_id' => 'T1',
        ]);

        $this->assertStringStartsWith('https://securepay.sslcommerz.com', $url);
        Http::assertSent(fn ($request) => str_starts_with(
            $request->url(), 'https://securepay.sslcommerz.com/gwprocess',
        ));
    }

    public function test_sandbox_mode_initiates_against_the_sandbox_host(): void
    {
        Http::fake([
            'sandbox.sslcommerz.com/gwprocess/*' => Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay/x',
            ]),
        ]);

        app(SslcommerzGateway::class)->initiate($this->settings(true), [
            'total_amount' => '10.00', 'tran_id' => 'T1',
        ]);

        Http::assertSent(fn ($request) => str_starts_with(
            $request->url(), 'https://sandbox.sslcommerz.com/gwprocess',
        ));
    }

    public function test_live_mode_validates_against_the_secure_pay_host(): void
    {
        Http::fake([
            'securepay.sslcommerz.com/validator/*' => Http::response(['status' => 'VALID']),
        ]);

        app(SslcommerzGateway::class)->validate($this->settings(false), 'VAL-1');

        Http::assertSent(fn ($request) => str_starts_with(
            $request->url(), 'https://securepay.sslcommerz.com/validator',
        ));
    }
}
