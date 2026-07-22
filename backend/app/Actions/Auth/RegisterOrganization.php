<?php

namespace App\Actions\Auth;

use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\UserRole;
use App\Enums\UserStatus;
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

            Domain::create([
                'organization_id' => $organization->id,
                'domain' => $slug.'.salonhub.com',
                'is_primary' => true,
                'is_verified' => false,
                'ssl_enabled' => false,
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
