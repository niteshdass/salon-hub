<?php

namespace App\Http\Controllers\Public;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Cross-tenant salon discovery for a customer who has arrived at the platform
 * without a salon in mind.
 *
 * Unlike every other public endpoint this one runs with NO tenant bound, which
 * leaves the BelongsToOrganization global scope inert by design. Nothing but
 * this class stands between a cross-tenant query and a leak, so every field
 * below is whitelisted by hand and no model is ever serialised.
 */
class DiscoveryController extends Controller
{
    /** Salons per page. */
    protected const PER_PAGE = 12;

    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));

        $term = $this->term($request);

        $query = Organization::query()->listable()->orderBy('name');

        if ($term !== null) {
            $like = '%'.$term.'%';

            $query->where(function ($match) use ($like) {
                $match
                    ->whereRaw('LOWER(organizations.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(organizations.slug) LIKE ?', [$like])
                    ->orWhereHas('branches', fn ($branches) => $branches
                        ->whereRaw('LOWER(branches.city) LIKE ?', [$like]))
                    ->orWhereHas('services', fn ($services) => $services
                        ->where('status', ServiceStatus::ACTIVE)
                        ->whereRaw('LOWER(services.name) LIKE ?', [$like]));
            });
        }

        $total = (clone $query)->count();

        /** @var Collection<int, Organization> $salons */
        $salons = $query
            ->withMin(
                ['services as price_from' => fn ($services) => $services
                    ->where('status', ServiceStatus::ACTIVE)],
                'price',
            )
            ->forPage($page, self::PER_PAGE)
            ->get(['id', 'name', 'slug', 'currency', 'logo', 'cover_image']);

        $ids = $salons->pluck('id')->all();
        $cities = $this->citiesFor($ids);
        $services = $this->servicesFor($ids);
        $ratings = $this->ratingsFor($ids);

        return response()->json([
            'data' => $salons->map(fn (Organization $salon) => [
                'slug' => $salon->slug,
                'name' => $salon->name,
                'city' => $cities[$salon->id] ?? null,
                'cover_image_url' => $this->url($salon->cover_image),
                'logo_url' => $this->url($salon->logo),
                'currency' => $salon->currency,
                'price_from' => $salon->price_from !== null
                    ? number_format((float) $salon->price_from, 2, '.', '')
                    : null,
                'rating' => $ratings[$salon->id] ?? null,
                'services' => $services[$salon->id] ?? [],
            ])->values()->all(),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => self::PER_PAGE,
            ],
        ]);
    }

    /**
     * The city of each salon's oldest branch, keyed by organization id. A
     * salon may have several branches; the first one it created is the one it
     * is known by.
     *
     * @param  array<int, int>  $organizationIds
     * @return array<int, string|null>
     */
    protected function citiesFor(array $organizationIds): array
    {
        if (! $organizationIds) {
            return [];
        }

        return Branch::query()
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('id')
            ->pluck('city', 'organization_id')
            ->all();
    }

    /**
     * Up to three active service names per salon, oldest first — enough for a
     * customer to see what kind of salon this is without opening it.
     *
     * @param  array<int, int>  $organizationIds
     * @return array<int, array<int, string>>
     */
    protected function servicesFor(array $organizationIds): array
    {
        if (! $organizationIds) {
            return [];
        }

        return Service::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('status', ServiceStatus::ACTIVE)
            ->orderBy('id')
            ->get(['organization_id', 'name'])
            ->groupBy('organization_id')
            ->map(fn (Collection $rows) => $rows->take(3)->pluck('name')->all())
            ->all();
    }

    /**
     * Published-review average and count per salon, but only once a salon has
     * three of them.
     *
     * A single review is noise, and a blank rating beside an established
     * salon's reads as "bad" when it only means "new" — so a salon below the
     * threshold shows no rating at all rather than a thin one.
     *
     * @param  array<int, int>  $organizationIds
     * @return array<int, array{average: float, count: int}>
     */
    protected function ratingsFor(array $organizationIds): array
    {
        if (! $organizationIds) {
            return [];
        }

        return Review::query()
            ->whereIn('organization_id', $organizationIds)
            ->where('status', 'published')
            ->selectRaw('organization_id, AVG(rating) as avg_rating, COUNT(*) as cnt')
            ->groupBy('organization_id')
            ->get()
            ->filter(fn ($row) => (int) $row->cnt >= 3)
            ->mapWithKeys(fn ($row) => [
                (int) $row->organization_id => [
                    'average' => round((float) $row->avg_rating, 1),
                    'count' => (int) $row->cnt,
                ],
            ])
            ->all();
    }

    protected function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * The search term, lowercased for matching, or null when the box is empty.
     *
     * Lowercasing both sides rather than trusting collation keeps sqlite (in
     * tests) and MySQL (in production) agreeing. The length cap bounds the
     * LIKE pattern; nobody types a salon name longer than this.
     */
    protected function term(Request $request): ?string
    {
        $raw = $request->query('q');
        $term = is_string($raw) ? trim($raw) : '';

        return $term === '' ? null : mb_strtolower(mb_substr($term, 0, 80));
    }
}
