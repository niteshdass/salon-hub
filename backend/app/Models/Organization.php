<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasFactory;

    /**
     * Slugs no salon may hold.
     *
     * A slug is not just a URL segment: registration mints
     * `<slug>.APP_DOMAIN` as a VERIFIED Domain row, and a verified row is what
     * Domain::resolveOrganizationForHost uses to decide whose data a request
     * is served. Two groups:
     *
     *  - The platform's own hostnames. app.APP_DOMAIN is the dashboard,
     *    www. the marketing site, api./admin./mail./static. are reserved for
     *    the product. Signing up as "App" must not hand a stranger a verified
     *    claim on one of them. This first group is mirrored exactly by
     *    RESERVED in frontend/src/lib/tenantHost.js, which refuses to read a
     *    salon out of those hosts; keep the two in step.
     *  - The public API's own URL vocabulary (routes/api.php). Not
     *    load-bearing since the host-resolved routes moved to their own
     *    `public-site` prefix, but a salon slugged "book" still produces URLs
     *    like /book/book/manage/... and would re-arm that collision the moment
     *    somebody adds a route under `public/`.
     *
     * Enforced in two places, because they catch different inputs:
     * RegisterRequest rejects a caller-supplied slug, and
     * RegisterOrganization::uniqueSlug refuses to generate one from a name.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = [
        'app',
        'www',
        'api',
        'admin',
        'mail',
        'static',
        'site',
        'services',
        'slots',
        'book',
        'manage',
        'payment',
    ];

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'email',
        'phone',
        'country',
        'timezone',
        'currency',
        'logo',
        'cover_image',
        'subscription_plan',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'subscription_plan' => SubscriptionPlan::class,
            'status' => OrganizationStatus::class,
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function serviceCategories(): HasMany
    {
        return $this->hasMany(ServiceCategory::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(Setting::class);
    }
}
