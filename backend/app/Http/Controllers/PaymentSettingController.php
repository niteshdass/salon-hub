<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\UpdatePaymentSettingRequest;
use App\Models\PaymentSetting;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Tenant-scoped public-booking payment configuration: the deposit customers
 * must put down and how they pay it. Owner only (enforced by the policy).
 * Gateway secrets, once present, are never returned — only a presence flag.
 */
class PaymentSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', PaymentSetting::class);

        return response()->json(['data' => $this->payload(PaymentSetting::query()->first())]);
    }

    public function update(UpdatePaymentSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $orgId = app(CurrentTenant::class)->id();

        $settings = PaymentSetting::firstOrNew(['organization_id' => $orgId]);
        $settings->deposit_type = $data['deposit_type'];
        $settings->deposit_value = $data['deposit_type'] === 'none' ? 0 : $data['deposit_value'];
        $settings->manual_enabled = $data['manual_enabled'];
        $settings->manual_account_number = $data['manual_account_number'] ?? null;
        $settings->manual_instructions = $data['manual_instructions'] ?? null;
        $settings->save();

        return response()->json(['data' => $this->payload($settings->fresh())]);
    }

    /**
     * Safe public shape. Gateway credentials never leave the server.
     *
     * @return array<string, mixed>
     */
    private function payload(?PaymentSetting $settings): array
    {
        return [
            'deposit_type' => $settings?->deposit_type?->value ?? 'none',
            'deposit_value' => number_format((float) ($settings?->deposit_value ?? 0), 2, '.', ''),
            'manual_enabled' => (bool) ($settings?->manual_enabled ?? false),
            'manual_account_number' => $settings?->manual_account_number,
            'manual_instructions' => $settings?->manual_instructions,
            'gateway' => $settings?->gateway ?? 'none',
            'has_gateway_credentials' => filled($settings?->credentials ?? null),
        ];
    }
}
