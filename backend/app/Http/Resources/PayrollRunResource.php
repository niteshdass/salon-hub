<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_month' => $this->period_month->toDateString(),
            'period_label' => $this->period_month->format('F Y'),
            'status' => $this->status->value,
            'total_salary' => $this->total_salary,
            'total_commission' => $this->total_commission,
            'total_tips' => $this->total_tips,
            'total_amount' => $this->total_amount,
            'finalized_at' => $this->finalized_at,
            'lines' => PayrollLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
