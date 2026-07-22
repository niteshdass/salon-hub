<?php

namespace App\Http\Resources;

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
            'services' => $this->whenLoaded('services', fn () => $this->services
                ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name])
                ->values()),
            'created_at' => $this->created_at,
        ];
    }
}
