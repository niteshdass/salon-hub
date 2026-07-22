<?php

namespace App\Tenancy;

use App\Models\Organization;

/**
 * Request-scoped holder for the active tenant (organization).
 * Bound as a singleton, so the same instance is shared across the
 * request lifecycle — the global scope reads it at query time.
 */
class CurrentTenant
{
    protected ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    public function forget(): void
    {
        $this->organization = null;
    }
}
