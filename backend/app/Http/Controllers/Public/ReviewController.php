<?php

namespace App\Http\Controllers\Public;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Appointment;
use App\Models\Review;
use Illuminate\Http\JsonResponse;

/**
 * Customer-facing review submission, reached from the token-based manage page.
 * The booking's public token is the credential; `public.tenant` has already
 * bound the organization, so the tenant scope isolates every query here.
 */
class ReviewController extends Controller
{
    public function store(StoreReviewRequest $request, string $org, string $token): JsonResponse
    {
        $appointment = Appointment::where('public_token', $token)
            ->firstOrFail()
            ->load('customer');

        // Only a finished visit can be reviewed.
        abort_unless(
            $this->isCompleted($appointment),
            422,
            'You can only review a completed appointment.',
        );

        // One review per appointment. Fail loudly rather than silently
        // overwriting the first one.
        abort_if(
            Review::where('appointment_id', $appointment->id)->exists(),
            409,
            'This appointment has already been reviewed.',
        );

        $review = Review::create([
            'appointment_id' => $appointment->id,
            'staff_id' => $appointment->staff_id,
            'rating' => $request->integer('rating'),
            'comment' => $request->input('comment'),
            'reviewer_name' => $appointment->customer?->name ?? 'Guest',
        ]);

        return (new ReviewResource($review))->response()->setStatusCode(201);
    }

    private function isCompleted(Appointment $appointment): bool
    {
        $status = $appointment->status instanceof AppointmentStatus
            ? $appointment->status
            : AppointmentStatus::from($appointment->status);

        return $status === AppointmentStatus::COMPLETED;
    }
}
