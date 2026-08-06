<?php

namespace App\Http\Controllers;

use App\Http\Requests\Review\UpdateReviewRequest;
use App\Http\Resources\ReviewAdminResource;
use App\Models\Review;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Dashboard-side reviews: read the salon's reviews and moderate them. The
 * tenant global scope limits the list and route-model binding to the current
 * organization, so a foreign review id 404s.
 */
class ReviewController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Review::class);

        $reviews = Review::query()
            ->with(['appointment.service', 'staff'])
            ->latest()
            ->get();

        $count = Review::query()->count();
        $average = $count > 0 ? round((float) Review::query()->avg('rating'), 1) : null;

        return ReviewAdminResource::collection($reviews)
            ->additional(['meta' => ['count' => $count, 'average' => $average]]);
    }

    public function update(UpdateReviewRequest $request, Review $review): ReviewAdminResource
    {
        $this->authorize('update', $review);

        $review->update(['status' => $request->validated('status')]);

        return new ReviewAdminResource($review->load(['appointment.service', 'staff']));
    }

    public function destroy(Review $review): Response
    {
        $this->authorize('delete', $review);

        $review->delete();

        return response()->noContent();
    }
}
