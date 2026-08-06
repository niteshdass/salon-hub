<?php

namespace App\Http\Controllers\Public;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Gallery;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
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

                // Social proof: only published reviews are ever exposed.
                'rating' => $this->ratingSummary(),
                'reviews' => $this->reviewsList(),
            ],
        ]);
    }

    /**
     * Average + count over the salon's published reviews. Average is null when
     * there are none, so the page can hide the stars entirely.
     *
     * @return array{average: float|null, count: int}
     */
    protected function ratingSummary(): array
    {
        $query = Review::query()->where('status', 'published');
        $count = (clone $query)->count();

        return [
            'average' => $count > 0 ? round((float) (clone $query)->avg('rating'), 1) : null,
            'count' => $count,
        ];
    }

    /**
     * The most recent published reviews, names softened to "First L." so the
     * public page never shows a customer's full name.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function reviewsList(): array
    {
        return Review::query()
            ->where('status', 'published')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'name' => $this->shortName($review->reviewer_name),
                'created_at' => $review->created_at,
            ])
            ->values()
            ->all();
    }

    /**
     * "Sarah Miller" => "Sarah M."; a single name is left as-is.
     */
    protected function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';

        if (count($parts) < 2) {
            return $first;
        }

        $lastInitial = strtoupper(substr((string) end($parts), 0, 1));

        return trim("{$first} {$lastInitial}.");
    }

    /**
     * Per-staff published-review aggregate, keyed by staff id.
     *
     * @return Collection<int, object{avg_rating: mixed, cnt: int}>
     */
    protected function staffRatings(): Collection
    {
        return Review::query()
            ->where('status', 'published')
            ->whereNotNull('staff_id')
            ->selectRaw('staff_id, AVG(rating) as avg_rating, COUNT(*) as cnt')
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function branches(Organization $organization): array
    {
        $hours = $this->hoursByBranch($organization);

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
            'hours' => $hours->get($branch->id, []),
        ])->values()->all();
    }

    /**
     * Opening hours for every branch, derived from the same
     * branches.opening_hours_json that SlotGenerator gates bookings on —
     * so the page can never advertise hours the booking form refuses.
     *
     * Monday first the way a salon would print it; Sunday closes the week.
     *
     * @return Collection<int, array<int, array<string, mixed>>>
     */
    protected function hoursByBranch(Organization $organization): Collection
    {
        // Keys are the three-letter weekday form stored in
        // branches.opening_hours_json (what SlotGenerator indexes by);
        // values are Carbon's dayOfWeek numbers (Sunday = 0), which the
        // existing payload contract uses. Emitted Monday-first.
        $week = [
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
            'sun' => 0,
        ];

        return Branch::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->mapWithKeys(function (Branch $branch) use ($week): array {
                $hours = $branch->opening_hours_json ?? [];

                $rows = [];
                foreach ($week as $name => $weekday) {
                    $pair = $hours[$name] ?? null;
                    $open = is_array($pair) ? ($pair[0] ?? null) : null;
                    $close = is_array($pair) ? ($pair[1] ?? null) : null;

                    $rows[] = [
                        'weekday' => $weekday,
                        'open_time' => $open,
                        'close_time' => $close,
                        // A day with no pair is a closed day, not a missing one.
                        'is_closed' => $open === null || $close === null,
                    ];
                }

                return [$branch->id => $rows];
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function team(Organization $organization): array
    {
        $ratings = $this->staffRatings();

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
                'rating' => $this->memberRating($ratings->get($member->id)),
            ])
            ->values()
            ->all();
    }

    /**
     * Shape one staff member's rating from a grouped aggregate row (or null
     * when they have no published reviews).
     *
     * @return array{average: float|null, count: int}
     */
    protected function memberRating(?object $row): array
    {
        return [
            'average' => $row ? round((float) $row->avg_rating, 1) : null,
            'count' => $row ? (int) $row->cnt : 0,
        ];
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
}
