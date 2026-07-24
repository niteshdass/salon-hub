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
            'method' => $this->method instanceof \BackedEnum ? $this->method->value : $this->method,
            'reference' => $this->reference,
            'notes' => $this->notes,
            // The staff account may since be gone; fall back to a dash.
            'recorded_by' => $this->recorder?->name,
            'created_at' => $this->created_at,
        ];
    }
}
