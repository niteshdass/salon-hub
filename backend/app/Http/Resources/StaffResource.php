<?php

namespace App\Http\Resources;

use App\Enums\PayType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->staffProfile;

        // Pay is the most sensitive field on a staff record: owners only.
        $isOwner = $request->user()?->isOwner() ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $profile?->phone,
            'role' => $this->role instanceof \BackedEnum ? $this->role->value : $this->role,
            'designation' => $profile?->designation,
            'bio' => $profile?->bio,
            'profile_image' => $profile?->profile_image,
            'working_days_json' => $profile?->working_days_json,
            'working_hours_json' => $profile?->working_hours_json,
            'pay_type' => $this->when($isOwner, fn () => ($profile?->pay_type ?? PayType::NONE)->value),
            'monthly_salary' => $this->when($isOwner, fn () => $profile?->monthly_salary ?? '0.00'),
            'commission_rate' => $this->when($isOwner, fn () => $profile?->commission_rate ?? '0.00'),
            'services' => $this->whenLoaded('services', fn () => $this->services
                ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name])
                ->values()),
            'created_at' => $this->created_at,
        ];
    }
}
