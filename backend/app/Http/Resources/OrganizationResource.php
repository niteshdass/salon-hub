<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'subscription_plan' => $this->subscription_plan instanceof \BackedEnum
                ? $this->subscription_plan->value
                : $this->subscription_plan,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'primary_domain' => $this->primaryDomain(),
        ];
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
