<?php

namespace App\Http\Controllers\Public;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Gallery;
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

    /** Sort values the endpoint accepts; anything else falls back to 'recommended'. */
    protected const SORTS = ['recommended', 'top_rated', 'price_asc'];

    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));

        $term = $this->term($request);
        $city = $this->city($request);
        $service = $this->service($request);
        $sort = $this->sort($request);

        $query = Organization::query()->listable();

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

            // A customer typing a salon's name wants that salon, not every
            // salon that happens to sell a service with a similar name.
            //
            // withMax()/withMin() below normally add `organizations.*` to the
            // select list for us, but only when nothing has selected yet;
            // selectRaw() here claims that slot first, so it has to bring
            // `organizations.*` along itself or every column this query
            // needs comes back null.
            $query
                ->addSelect('organizations.*')
                ->selectRaw(
                    'CASE WHEN LOWER(organizations.name) LIKE ? THEN 0 ELSE 1 END as name_rank',
                    [$like],
                )
                ->orderBy('name_rank');
        }

        // `city` is an exact match (case-insensitive): the chip row offers a
        // fixed set of cities pulled from real branch data, so there is never
        // a reason to substring-match here the way the free-text search does.
        if ($city !== null) {
            $query->whereHas('branches', fn ($branches) => $branches
                ->whereRaw('LOWER(branches.city) = ?', [$city]));
        }

        // `service` narrows to salons selling something matching the chip's
        // keyword. There is no shared cross-tenant category table — each
        // salon's service_categories are its own — so this reuses the same
        // substring match the search box uses rather than pretending a
        // taxonomy exists.
        if ($service !== null) {
            $query->whereHas('services', fn ($services) => $services
                ->where('status', ServiceStatus::ACTIVE)
                ->whereRaw('LOWER(services.name) LIKE ?', ['%'.$service.'%']));
        }

        $query->withMin(
            ['services as price_from' => fn ($services) => $services
                ->where('status', ServiceStatus::ACTIVE)],
            'price',
        );

        if ($sort === 'top_rated') {
            // Same three-review floor as the card itself (see ratingsFor) —
            // sorting a one-review 5-star salon to the top would rank a card
            // that then shows no rating at all above ones that do.
            $query
                ->withCount(['reviews as rating_count' => fn ($reviews) => $reviews
                    ->where('status', 'published')])
                ->withAvg(['reviews as rating_avg' => fn ($reviews) => $reviews
                    ->where('status', 'published')], 'rating')
                ->orderByRaw('CASE WHEN rating_count >= 3 THEN rating_avg ELSE NULL END IS NULL')
                ->orderByRaw('CASE WHEN rating_count >= 3 THEN rating_avg ELSE NULL END DESC');
        } elseif ($sort === 'price_asc') {
            $query->orderByRaw('price_from IS NULL')->orderBy('price_from');
        } else {
            // 'recommended': a salon that recently took a booking is a salon
            // that still exists. Nulls last: never booked is worse than
            // booked long ago. The boolean expression evaluates to 0/1 on
            // both sqlite and MySQL.
            $query
                ->withMax('appointments', 'created_at')
                ->orderByRaw('appointments_max_created_at IS NULL, appointments_max_created_at DESC');
        }

        // `organizations.name` is not unique, so two same-named salons could
        // otherwise tie on every key above. Break the tie on id — the only
        // column guaranteed unique here — so pagination can never repeat or
        // skip a salon. Qualified because the query may aggregate over
        // `appointments`/`reviews`, which also have an `id` column.
        $query->orderBy('organizations.name')->orderBy('organizations.id');

        // Counted off a clean copy: `count()` over the ranking select
        // expressions is both wasteful and, with an ORDER BY on an alias,
        // invalid on MySQL.
        $total = (clone $query)->reorder()->count('organizations.id');

        // get()'s column list is NOT a whitelist: withMax()/withMin() above
        // already forced `organizations.*` onto the select (or, on the
        // $term branch, addSelect('organizations.*') did), and
        // onceWithColumns() only applies a get() column list when nothing
        // has selected yet. So every organizations column — email, phone,
        // uuid, status, etc — comes back on $salons regardless of what is
        // passed here. The only real whitelist is the hand-built ->map()
        // below; never swap it for ->toArray() or an API Resource without
        // keeping that in mind, and never add an $appends accessor to
        // Organization that would leak through it.
        /** @var Collection<int, Organization> $salons */
        $salons = $query->forPage($page, self::PER_PAGE)->get();

        $ids = $salons->pluck('id')->all();
        $cities = $this->citiesFor($ids);
        $services = $this->servicesFor($ids);
        $ratings = $this->ratingsFor($ids);
        $gallery = $this->galleryCoversFor($ids);

        return response()->json([
            'data' => $salons->map(fn (Organization $salon) => [
                'slug' => $salon->slug,
                'name' => $salon->name,
                'city' => $cities[$salon->id] ?? null,
                'cover_image_url' => $this->url($salon->cover_image ?: ($gallery[$salon->id] ?? null)),
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
                'facets' => [
                    'cities' => $this->availableCities(),
                ],
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
     * The first gallery photo of each salon, keyed by organization id. Most
     * salons finish setup with photos but no cover image, and a card with an
     * empty band reads as broken — the gallery already holds a picture of the
     * place, so the card borrows it.
     *
     * @param  array<int, int>  $organizationIds
     * @return array<int, string>
     */
    protected function galleryCoversFor(array $organizationIds): array
    {
        if (! $organizationIds) {
            return [];
        }

        // Reversed so the lowest sort_order (then lowest id) is plucked last
        // and wins the key — the same photo the salon's own site leads with.
        return Gallery::query()
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->pluck('image', 'organization_id')
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

    /** The city chip's value, lowercased for an exact match, or null when unset. */
    protected function city(Request $request): ?string
    {
        $raw = $request->query('city');
        $city = is_string($raw) ? trim($raw) : '';

        return $city === '' ? null : mb_strtolower(mb_substr($city, 0, 80));
    }

    /** The service chip's value, lowercased for a substring match, or null when unset. */
    protected function service(Request $request): ?string
    {
        $raw = $request->query('service');
        $service = is_string($raw) ? trim($raw) : '';

        return $service === '' ? null : mb_strtolower(mb_substr($service, 0, 80));
    }

    /** One of self::SORTS; anything unrecognised is treated as 'recommended'. */
    protected function sort(Request $request): string
    {
        $sort = $request->query('sort');

        return in_array($sort, self::SORTS, true) ? $sort : 'recommended';
    }

    /**
     * Every city a listed salon can be found in, alphabetised — the chip row's
     * option list. Computed off the full listable set, not the current
     * filters, so choosing one city doesn't make the others disappear from
     * the row.
     *
     * @return list<string>
     */
    protected function availableCities(): array
    {
        return Branch::query()
            ->whereHas('organization', fn ($organizations) => $organizations->listable())
            ->whereNotNull('city')
            ->distinct()
            ->orderBy('city')
            ->pluck('city')
            ->all();
    }
}
