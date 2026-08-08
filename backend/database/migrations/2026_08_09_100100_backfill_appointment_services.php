<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every existing booking becomes a one-line booking.
     *
     * The line's price is the appointment's own snapshot price, not the
     * service's current price: that snapshot is what the customer was quoted,
     * and it is what the invoice and the revenue reports have always shown.
     * Duration comes from the service because the appointment never stored
     * one; where the service is already gone, it is derived from the booked
     * window instead.
     *
     * Skips appointments that already have a line, so a re-run is a no-op.
     */
    public function up(): void
    {
        DB::table('appointments')
            ->leftJoin('services', 'services.id', '=', 'appointments.service_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('appointment_services')
                    ->whereColumn('appointment_services.appointment_id', 'appointments.id');
            })
            ->select([
                'appointments.id',
                'appointments.service_id',
                'appointments.price',
                'appointments.start_time',
                'appointments.end_time',
                'services.name',
                'services.duration',
            ])
            // chunkById, not chunk: inserting a line makes its appointment stop
            // matching whereNotExists, so an offset-paged chunk() would step
            // straight over every row the previous page just fixed.
            ->chunkById(500, function ($rows): void {
                $now = now();
                $lines = [];

                foreach ($rows as $row) {
                    $lines[] = [
                        'appointment_id' => $row->id,
                        'service_id' => $row->service_id,
                        'name' => $row->name ?? 'Service',
                        'price' => $row->price,
                        'duration' => $row->duration ?? $this->minutesBetween($row->start_time, $row->end_time),
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($lines !== []) {
                    DB::table('appointment_services')->insert($lines);
                }
            }, 'appointments.id', 'id');
    }

    /**
     * The booking has no service left to ask, so its own window is the only
     * remaining record of how long it took.
     */
    private function minutesBetween(string $start, string $end): int
    {
        return (int) max(0, (strtotime($end) - strtotime($start)) / 60);
    }

    public function down(): void
    {
        // The lines are wholly derived from appointments.service_id, which
        // still exists at this point; clearing them is a clean reversal.
        DB::table('appointment_services')->delete();
    }
};
