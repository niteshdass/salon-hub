<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'staff_name' => $this->staff_name,
            'pay_type' => $this->pay_type->value,
            'commission_rate' => $this->commission_rate,
            'monthly_salary' => $this->monthly_salary,
            'earned_revenue' => $this->earned_revenue,
            'bookings' => $this->bookings,
            'salary_amount' => $this->salary_amount,
            'commission_amount' => $this->commission_amount,
            'tips_amount' => $this->tips_amount,
            'total_amount' => $this->total_amount,
        ];
    }
}
