<?php

namespace App\Http\Controllers\Public;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BusinessHour;
use App\Models\Gallery;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the salon's public page renders, in one unauthenticated
 * request. No authentication: `public.tenant` resolves the organization
 * from the {org} slug before this runs, after which the tenant global
 * scope isolates every query below.
 *
 * Only what a visitor should see leaves here — staff appear as people,
 * not as accounts.
 */
class SiteController extends Controller
{
    public function __invoke(CurrentTenant $tenant): JsonResponse
    {
        $organization = $tenant->get();
        $settings = Setting::query()->where('organization_id', $organization->id)->first();

        return response()->json([
            'data' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'email' => $organization->email,
                'phone' => $organization->phone,
                // Prices on the page are the salon's, not the visitor's.
                'currency' => $organization->currency,
                'logo_url' => $this->url($organization->logo),
                'cover_image_url' => $this->url($organization->cover_image),

                // Defaults mirror the settings table, so a salon that has
                // never opened the settings page still renders.
                'theme_color' => $settings?->theme_color ?? '#6366f1',
                'about' => $settings?->about,
                'social' => [
                    'facebook' => $settings?->facebook,
                    'instagram' => $settings?->instagram,
                    'website' => $settings?->website,
                ],

                'branches' => $this->branches($organization),
                'team' => $this->team($organization),
                'gallery' => $this->gallery(),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function branches(Organization $organization): array
    {
        $hours = $this->hoursByBranch();

        return Branch::query()->orderBy('id')->get()->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'name' => $branch->name,
            'address' => $branch->address,
            'city' => $branch->city,
            'country' => $branch->country ?? $organization->country,
            'phone' => $branch->phone ?? $organization->phone,
            'email' => $branch->email,
            // Cast off the decimal string: these feed a map, not a ledger.
            'latitude' => $branch->latitude !== null ? (float) $branch->latitude : null,
            'longitude' => $branch->longitude !== null ? (float) $branch->longitude : null,
            'hours' => $hours->get($branch->id, collect())->values()->all(),
        ])->values()->all();
    }

    /**
     * Opening hours for every branch at once, Monday first the way a salon
     * would print them — Sunday closes the week rather than opening it.
     *
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    protected function hoursByBranch(): Collection
    {
        return BusinessHour::query()
            ->whereIn('branch_id', Branch::query()->select('id'))
            ->orderByRaw('CASE WHEN weekday = 0 THEN 7 ELSE weekday END')
            ->get()
            ->groupBy('branch_id')
            ->map(fn (Collection $rows) => $rows->map(fn (BusinessHour $hour) => [
                'weekday' => $hour->weekday,
                'open_time' => $this->time($hour->open_time),
                'close_time' => $this->time($hour->close_time),
                'is_closed' => $hour->is_closed,
            ]));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function team(Organization $organization): array
    {
        return User::query()
            // Users carry no tenant scope of their own — logging in has to
            // find an account before an organization is known.
            ->where('organization_id', $organization->id)
            ->where('role', UserRole::STAFF->value)
            ->where('status', 'active')
            ->with('staffProfile')
            ->orderBy('name')
            ->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'designation' => $member->staffProfile?->designation,
                'bio' => $member->staffProfile?->bio,
                // Free text on the profile: already a URL more often than a path.
                'photo_url' => $member->staffProfile?->profile_image,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function gallery(): array
    {
        return Gallery::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Gallery $image) => [
                'id' => $image->id,
                'title' => $image->title,
                'image_url' => $this->url($image->image),
            ])
            ->values()
            ->all();
    }

    protected function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    protected function time(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->format('H:i') : null;
    }
}
