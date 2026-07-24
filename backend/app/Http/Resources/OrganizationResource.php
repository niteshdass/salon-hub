<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            // Stored as disk paths; clients need URLs.
            'logo_url' => $this->image($this->logo),
            'cover_image_url' => $this->image($this->cover_image),
            'subscription_plan' => $this->subscription_plan instanceof \BackedEnum
                ? $this->subscription_plan->value
                : $this->subscription_plan,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'primary_domain' => $this->primaryDomain(),
        ];
    }

    protected function image(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * Resolve the primary domain string safely, using the loaded relation when available.
     */
    protected function primaryDomain(): ?string
    {
        if ($this->relationLoaded('domains')) {
            return $this->domains->firstWhere('is_primary', true)?->domain;
        }

        return $this->domains()->where('is_primary', true)->value('domain');
    }
}
