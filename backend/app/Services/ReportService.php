<?php

namespace App\Services;

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
            'summary' => [],
            'revenue' => [],
            'top_services' => [],
            'staff' => [],
            'bookings' => [],
        ];
    }
}
