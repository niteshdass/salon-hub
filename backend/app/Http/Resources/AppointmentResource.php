<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_date' => $this->booking_date instanceof Carbon
                ? $this->booking_date->format('Y-m-d')
                : $this->booking_date,
            'start_time' => $this->formatTime($this->start_time),
            'end_time' => $this->formatTime($this->end_time),
            'price' => $this->price,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'notes' => $this->notes,
            'branch' => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null,
            'services' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->service_id,
                'name' => $line->name,
                'price' => $line->price,
                'duration' => $line->duration,
            ])->values(), []),
            // The whole visit, so the calendar can size a block without
            // re-adding the lines on the client.
            'duration' => $this->whenLoaded('lines', fn () => (int) $this->lines->sum('duration'), 0),
            'staff' => $this->staff ? [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Present a stored 'H:i:s' time as 'H:i'.
     */
    protected function formatTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        return substr($time, 0, 5);
    }
}
