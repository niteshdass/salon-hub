<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Organization;
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

        $query = Organization::query()->listable()->orderBy('name');

        $total = (clone $query)->count();

        /** @var Collection<int, Organization> $salons */
        $salons = $query
            ->forPage($page, self::PER_PAGE)
            ->get(['id', 'name', 'slug', 'currency', 'logo', 'cover_image']);

        $cities = $this->citiesFor($salons->pluck('id')->all());

        return response()->json([
            'data' => $salons->map(fn (Organization $salon) => [
                'slug' => $salon->slug,
                'name' => $salon->name,
                'city' => $cities[$salon->id] ?? null,
                'cover_image_url' => $this->url($salon->cover_image),
                'logo_url' => $this->url($salon->logo),
                'currency' => $salon->currency,
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

    protected function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
