<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

/**
 * Computes the salon's reports for a date range. All money is "earned":
 * the SUM of the snapshot price on COMPLETED appointments. Every query
 * relies on the tenant global scope already being bound.
 */
class ReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $from, string $to): array
    {
        return [
            'range' => ['from' => $from, 'to' => $to],
            'summary' => $this->summary($from, $to),
            'revenue' => $this->revenueSeries($from, $to),
            'top_services' => $this->topServices($from, $to),
            'staff' => $this->staffPerformance($from, $to),
            'bookings' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(string $from, string $to): array
    {
        $current = $this->earnedWindow($from, $to);

        // Previous window: the equal-length span ending the day before $from.
        $length = Carbon::parse($from)->diffInDays(Carbon::parse($to)); // inclusive gap
        $prevTo = Carbon::parse($from)->subDay();
        $prevFrom = $prevTo->copy()->subDays($length);
        $previous = $this->earnedWindow($prevFrom->toDateString(), $prevTo->toDateString());

        return [
            'earned' => $current['earned'],
            'bookings' => $current['bookings'],
            'avg_ticket' => $current['avg_ticket'],
            'previous' => $previous,
            'delta' => [
                'earned_pct' => $this->pctChange($previous['earned'], $current['earned']),
                'bookings_pct' => $this->pctChange($previous['bookings'], $current['bookings']),
            ],
        ];
    }

    /**
     * Earned total, completed-booking count, and average ticket for a window.
     *
     * @return array{earned: float, bookings: int, avg_ticket: float}
     */
    protected function earnedWindow(string $from, string $to): array
    {
        $query = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to);

        $bookings = (clone $query)->count();
        $earned = round((float) (clone $query)->sum('price'), 2);

        return [
            'earned' => $earned,
            'bookings' => $bookings,
            'avg_ticket' => $bookings > 0 ? round($earned / $bookings, 2) : 0.0,
        ];
    }

    /**
     * Percentage change from $old to $new. Null when there is no baseline
     * (an infinite jump from zero is not a meaningful percentage).
     */
    protected function pctChange(float|int $old, float|int $new): ?float
    {
        if ((float) $old === 0.0) {
            return null;
        }

        return round((($new - $old) / $old) * 100, 1);
    }

    /**
     * Earned + bookings bucketed across the range, zero-filled. Bucketing is
     * done in PHP so it behaves identically on MySQL and SQLite.
     *
     * @return array{granularity: string, points: array<int, array<string, mixed>>}
     */
    protected function revenueSeries(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $granularity = $this->granularityFor($start, $end);

        // One row per completed appointment in range; sum in PHP.
        $rows = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->get(['booking_date', 'price']);

        $earned = [];   // period key => float
        $bookings = []; // period key => int
        foreach ($rows as $row) {
            $key = $this->periodKey(Carbon::parse($row->booking_date), $granularity);
            $earned[$key] = ($earned[$key] ?? 0) + (float) $row->price;
            $bookings[$key] = ($bookings[$key] ?? 0) + 1;
        }

        // Zero-filled, ordered points across the whole range.
        $points = [];
        foreach ($this->periodCursors($start, $end, $granularity) as $cursor) {
            $key = $this->periodKey($cursor, $granularity);
            $points[] = [
                'period' => $key,
                'label' => $this->periodLabel($cursor, $granularity),
                'earned' => round($earned[$key] ?? 0, 2),
                'bookings' => $bookings[$key] ?? 0,
            ];
        }

        return ['granularity' => $granularity, 'points' => $points];
    }

    protected function granularityFor(Carbon $start, Carbon $end): string
    {
        $days = $start->diffInDays($end);
        if ($days <= 31) {
            return 'day';
        }
        if ($days <= 182) {
            return 'week';
        }

        return 'month';
    }

    protected function periodKey(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'day' => $date->toDateString(),          // 2026-07-24
            'week' => $date->isoFormat('GGGG-[W]WW'), // 2026-W30 (ISO week)
            'month' => $date->format('Y-m'),          // 2026-07
        };
    }

    protected function periodLabel(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'day' => $date->format('M j'),                       // Jul 24
            'week' => $date->copy()->startOfWeek()->format('M j'), // Jul 20 (Monday of the week)
            'month' => $date->format('M Y'),                      // Jul 2026
        };
    }

    /**
     * Ordered cursors from start to end, one per bucket.
     *
     * @return array<int, Carbon>
     */
    protected function periodCursors(Carbon $start, Carbon $end, string $granularity): array
    {
        $cursors = [];
        $cursor = match ($granularity) {
            'day' => $start->copy(),
            'week' => $start->copy()->startOfWeek(),
            'month' => $start->copy()->startOfMonth(),
        };

        while ($cursor->lessThanOrEqualTo($end)) {
            $cursors[] = $cursor->copy();
            $cursor = match ($granularity) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
            };
        }

        return $cursors;
    }

    /**
     * Completed bookings grouped by service, ranked by earned. Group-by-id +
     * SUM is portable SQL; names are resolved with a tenant-scoped lookup.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function topServices(string $from, string $to): array
    {
        $rows = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->selectRaw('service_id, COUNT(*) as bookings, SUM(price) as earned')
            ->groupBy('service_id')
            ->get();

        $total = (float) $rows->sum('earned');
        $names = Service::query()->pluck('name', 'id');

        return $rows
            ->sortByDesc(fn ($row) => (float) $row->earned)
            ->take(10)
            ->map(fn ($row) => [
                'service_id' => (int) $row->service_id,
                'name' => $names->get($row->service_id, 'Unknown'),
                'bookings' => (int) $row->bookings,
                'earned' => round((float) $row->earned, 2),
                'share_pct' => $total > 0 ? round((float) $row->earned / $total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * Completed bookings + earned per staff member, ranked by earned, each
     * with their average review rating over the same window.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function staffPerformance(string $from, string $to): array
    {
        $rows = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $from)
            ->whereDate('booking_date', '<=', $to)
            ->selectRaw('staff_id, COUNT(*) as bookings, SUM(price) as earned')
            ->groupBy('staff_id')
            ->get();

        // Staff names: User carries no tenant scope, so filter explicitly.
        $names = User::query()
            ->where('organization_id', app(CurrentTenant::class)->id())
            ->where('role', UserRole::STAFF->value)
            ->pluck('name', 'id');

        $ratings = $this->staffRatings($from, $to);

        return $rows
            ->sortByDesc(fn ($row) => (float) $row->earned)
            ->map(fn ($row) => [
                'staff_id' => (int) $row->staff_id,
                'name' => $names->get($row->staff_id, 'Unknown'),
                'bookings' => (int) $row->bookings,
                'earned' => round((float) $row->earned, 2),
                'rating' => $this->ratingFor($ratings->get($row->staff_id)),
            ])
            ->values()
            ->all();
    }

    /**
     * Per-staff review aggregate over the range, keyed by staff id.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function staffRatings(string $from, string $to): \Illuminate\Support\Collection
    {
        return Review::query()
            ->where('status', 'published')
            ->whereNotNull('staff_id')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->selectRaw('staff_id, AVG(rating) as avg_rating, COUNT(*) as cnt')
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');
    }

    /**
     * @return array{average: float|null, count: int}
     */
    protected function ratingFor(?object $row): array
    {
        return [
            'average' => $row ? round((float) $row->avg_rating, 1) : null,
            'count' => $row ? (int) $row->cnt : 0,
        ];
    }
}
