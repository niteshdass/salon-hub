<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review as the dashboard shows it: the rating and comment plus moderation
 * state and light appointment context (service, staff, date) so the owner can
 * see what each review is about without extra lookups.
 */
class ReviewAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'reviewer_name' => $this->reviewer_name,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'service_name' => $this->appointment?->service?->name,
            'staff_name' => $this->staff?->name,
            'booking_date' => $this->appointment?->booking_date?->toDateString(),
        ];
    }
}
