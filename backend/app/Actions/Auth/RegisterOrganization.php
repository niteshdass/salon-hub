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
     * Build a slug that is unique against the organizations table.
     */
    protected function uniqueSlug(string $source): string
    {
        $base = Str::slug($source);
        $slug = $base;
        $suffix = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
