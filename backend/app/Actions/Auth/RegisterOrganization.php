<?php

namespace App\Actions\Auth;

use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterOrganization
{
    /**
     * Mon–Sat 09:00–18:00, closed Sunday. A conventional salon week the
     * owner can edit in Settings; null means closed that day, matching
     * SlotGenerator's reading of branches.opening_hours_json.
     *
     * Keys are the three-letter weekday form SlotGenerator indexes by
     * (`strtolower(Carbon::parse($date)->format('D'))`), the same shape
     * BranchFactory already produces.
     */
    protected const DEFAULT_OPENING_HOURS = [
        'mon' => ['09:00', '18:00'],
        'tue' => ['09:00', '18:00'],
        'wed' => ['09:00', '18:00'],
        'thu' => ['09:00', '18:00'],
        'fri' => ['09:00', '18:00'],
        'sat' => ['09:00', '18:00'],
        'sun' => null,
    ];

    /**
     * Register a new organization along with its owner, primary domain and settings.
     *
     * @param  array<string, mixed>  $data
     * @return array{organization: Organization, user: User}
     */
    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $slug = $this->uniqueSlug($data['slug'] ?? $data['salon_name']);

            $organization = Organization::create([
                'uuid' => (string) Str::uuid(),
                'name' => $data['salon_name'],
                'slug' => $slug,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'country' => $data['country'] ?? null,
                'timezone' => $data['timezone'] ?? 'UTC',
                'currency' => $data['currency'] ?? 'USD',
                'subscription_plan' => SubscriptionPlan::FREE->value,
                'status' => OrganizationStatus::ACTIVE->value,
            ]);

            $user = User::create([
                'organization_id' => $organization->id,
                'name' => $data['name'] ?? $data['salon_name'].' Owner',
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => UserRole::OWNER->value,
                'status' => UserStatus::ACTIVE->value,
            ]);

            // A salon is not bookable without a branch: it carries the
            // address, the map pin and the opening hours SlotGenerator
            // reads. Create one now so registration ends in a usable
            // state rather than an empty dashboard.
            Branch::create([
                'organization_id' => $organization->id,
                'name' => $data['salon_name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'],
                'country' => $data['country'] ?? null,
                'opening_hours_json' => self::DEFAULT_OPENING_HOURS,
            ]);

            Domain::create([
                'organization_id' => $organization->id,
                // config('app.domain') reads the same APP_DOMAIN that CORS
                // trusts, so the host we mint here is always a host we answer
                // on.
                'domain' => $slug.'.'.config('app.domain'),
                // Served immediately by the wildcard vhost and wildcard cert:
                // there is nothing to verify for a subdomain we control, and
                // Domain::resolveOrganizationForHost only answers on verified
                // rows. A future custom domain (v1.2) is the case that starts
                // false, because that host is one a stranger has merely
                // claimed.
                'is_primary' => true,
                'is_verified' => true,
                'ssl_enabled' => true,
            ]);

            Setting::create([
                'organization_id' => $organization->id,
            ]);

            return [
                'organization' => $organization,
                'user' => $user,
            ];
        });
    }

    /**
     * Longest a single DNS label may be. The slug is minted as
     * `<slug>.APP_DOMAIN` and served by a wildcard vhost, so it is a label,
     * not a whole hostname — mirrored by `max:63` in RegisterRequest.
     */
    protected const MAX_SLUG_LENGTH = 63;

    /**
     * Fallback base when a salon name yields no slug at all.
     */
    protected const FALLBACK_SLUG = 'salon';

    /**
     * Build a slug that is unique against the organizations table, is not one
     * of the platform's reserved names, and is a usable DNS label.
     *
     * The reserved check belongs here and not only in RegisterRequest: the
     * request rule sees a slug the caller sent, while a salon merely NAMED
     * "App" arrives with no slug at all and would otherwise be handed
     * app.APP_DOMAIN — a verified host that selects the tenant — without
     * anyone having asked for it. A reserved base takes the same numeric
     * suffix a collision does, so "App" becomes "app-2".
     *
     * The same argument applies to shape and length, which is why they are
     * enforced here too rather than only in the request:
     *
     *  - Str::slug() returns '' for a name with no transliterable characters
     *    (Str::slug('💇') === ''), and '' is neither reserved nor taken, so it
     *    was accepted — producing an organization whose minted host is
     *    ".APP_DOMAIN" and which has no way to recover, since there is no
     *    slug-change flow. Bengali and Arabic transliterate fine, so this is
     *    narrow, but it is unrecoverable for whoever hits it.
     *  - A long salon name overflows the 63-octet DNS label limit:
     *    Str::slug(str_repeat('beauty ', 20)) is 139 characters. The
     *    collision suffix is applied through compose(), which shortens the
     *    base to make room rather than pushing the result back over the cap.
     */
    protected function uniqueSlug(string $source): string
    {
        $base = $this->compose(trim(Str::slug($source), '-'), '');

        if ($base === '') {
            $base = self::FALLBACK_SLUG;
        }

        $slug = $base;
        $suffix = 2;

        while (in_array($slug, Organization::RESERVED_SLUGS, true)
            || Organization::where('slug', $slug)->exists()) {
            $slug = $this->compose($base, '-'.$suffix);
            $suffix++;
        }

        return $slug;
    }

    /**
     * Join a base and a suffix into at most MAX_SLUG_LENGTH characters,
     * trimming the base (never the suffix, which is what makes the result
     * unique) and never leaving a trailing hyphen where the cut landed.
     */
    protected function compose(string $base, string $suffix): string
    {
        $head = rtrim(substr($base, 0, self::MAX_SLUG_LENGTH - strlen($suffix)), '-');

        if ($head === '') {
            // Only reachable from the empty-name case; uniqueSlug substitutes
            // the fallback for the bare base, so keep the two consistent.
            return $suffix === '' ? '' : self::FALLBACK_SLUG.$suffix;
        }

        return $head.$suffix;
    }
}
