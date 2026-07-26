<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
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
            'revenue' => [],
            'top_services' => [],
            'staff' => [],
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
}
