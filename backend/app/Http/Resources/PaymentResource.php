<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'tip_amount' => $this->tip_amount,
            'method' => $this->method instanceof \BackedEnum ? $this->method->value : $this->method,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'source' => $this->source instanceof \BackedEnum ? $this->source->value : $this->source,
            'reference' => $this->reference,
            // Gateway transaction id, for reconciling an online deposit against
            // the SSLCommerz report. Null for counter / manual payments.
            'transaction_id' => $this->transaction_id,
            'refunded_at' => $this->refunded_at,
            'notes' => $this->notes,
            // The staff account may since be gone; fall back to a dash.
            'recorded_by' => $this->recorder?->name,
            'created_at' => $this->created_at,
        ];
    }
}
