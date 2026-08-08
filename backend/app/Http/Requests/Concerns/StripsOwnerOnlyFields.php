<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Arr;

/**
 * Some fields on a request are owner-only. A non-owner's request keeps
 * working; those fields are simply removed before validation ever sees
 * them, so an unrelated edit still succeeds rather than 422ing.
 */
trait StripsOwnerOnlyFields
{
    /**
     * @return array<int, string>
     */
    abstract protected function ownerOnlyFields(): array;

    protected function prepareForValidation(): void
    {
        if (! ($this->user()?->isOwner() ?? false)) {
            $this->replace(Arr::except($this->all(), $this->ownerOnlyFields()));
        }
    }
}
