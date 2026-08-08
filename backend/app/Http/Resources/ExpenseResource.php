<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'expense_date' => $this->expense_date->toDateString(),
            'amount' => $this->amount,
            'note' => $this->note,
            'branch_id' => $this->branch_id,
            'payroll_run_id' => $this->payroll_run_id,
            'is_locked' => $this->isSystemGenerated(),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'created_at' => $this->created_at,
        ];
    }
}
