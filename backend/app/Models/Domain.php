<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'domain',
        'is_primary',
        'is_verified',
        'ssl_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'bool',
            'is_verified' => 'bool',
            'ssl_enabled' => 'bool',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Map a Host header to the organization it serves, or null.
     *
     * This is the single answer to "which Domain rows resolve a tenant", and
     * both tenant middlewares go through it. Every table in this app carries
     * organization_id against one shared database, so the three rules below
     * are the isolation boundary, not routing convenience:
     *
     *  1. The host is normalised the way DNS treats it. Request::getHost()
     *     has already lowercased it and stripped any `:port`; the trailing
     *     root dot in `beauty-queen.salonhub.com.` is stripped here. All
     *     three spellings of one name reach one tenant, and no spelling of
     *     one name reaches a different one.
     *  2. Only a VERIFIED row resolves. Registration mints the salon's own
     *     `<slug>.APP_DOMAIN` verified because a wildcard vhost and wildcard
     *     certificate already serve it. The custom-domain flow (v1.2) will
     *     insert rows naming hosts the claimant may not control; an
     *     unverified row that resolved would make inserting one a takeover.
     *  3. Only an ACTIVE organization resolves, matching what the {org}-slug
     *     branch of ResolvePublicTenant requires. A suspended salon must not
     *     keep serving from its subdomain after it stops serving by path.
     *
     * `is_primary` is deliberately NOT required: a v1.2 custom domain is a
     * second, non-primary row for a salon that already has a primary one,
     * and requiring the flag would silently break that feature.
     */
    public static function resolveOrganizationForHost(?string $host): ?Organization
    {
        $host = rtrim(strtolower(trim((string) $host)), '.');

        if ($host === '') {
            return null;
        }

        return static::query()
            ->where('domain', $host)
            ->where('is_verified', true)
            ->first()
            ?->organization()
            ->where('status', OrganizationStatus::ACTIVE->value)
            ->first();
    }
}
