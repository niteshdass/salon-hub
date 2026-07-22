<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies automatic tenant isolation to a model that owns an
 * `organization_id` column.
 *
 * The global scope + auto-fill only activate WHEN a tenant is bound
 * (via ResolveTenant middleware). With no tenant bound — registration,
 * login, host resolution, console/seeders — queries run unscoped.
 */
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        $tenant = app(CurrentTenant::class);

        static::addGlobalScope('organization', function (Builder $builder) use ($tenant): void {
            if ($tenant->check()) {
                $builder->where(
                    $builder->getModel()->getTable().'.organization_id',
                    $tenant->id(),
                );
            }
        });

        static::creating(function ($model) use ($tenant): void {
            if ($tenant->check() && empty($model->organization_id)) {
                $model->organization_id = $tenant->id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
