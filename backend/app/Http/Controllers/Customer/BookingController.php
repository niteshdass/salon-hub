<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Review;
use App\Services\AppointmentScheduler;
use App\Services\BookingNotifier;
use App\Services\SlotGenerator;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The logged-in customer's bookings across every salon. No tenant is bound,
 * so the tenant global scope is inert and these queries reach all orgs — which
 * is exactly why every query is filtered by the account's own customer rows.
 */
class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ids = $this->ownedCustomerIds($request);

        $appointments = Appointment::whereIn('customer_id', $ids)
            ->with(['organization', 'service', 'staff', 'branch', 'review', 'payments'])
            ->get();

        $today = now()->toDateString();

        [$upcoming, $past] = $appointments->partition(
            fn (Appointment $a) => $a->booking_date->toDateString() >= $today && $a->isChangeable()
        );

        $upcoming = $upcoming->sortBy([['booking_date', 'asc'], ['start_time', 'asc']])
            ->map(fn (Appointment $a) => $this->present($a))->values();
        $past = $past->sortByDesc(fn (Appointment $a) => $a->booking_date->toDateString().$a->start_time)
            ->map(fn (Appointment $a) => $this->present($a))->values();

        return response()->json(['data' => ['upcoming' => $upcoming, 'past' => $past]]);
    }

    public function cancel(Request $request, string $appointment, BookingNotifier $notifier): JsonResponse
    {
        $booking = $this->ownedBooking($request, $appointment);

        if (! $booking->isChangeable()) {
            return response()->json(['message' => 'This booking can no longer be changed.'], 422);
        }

        $this->bindTenant($booking);
        $booking->update(['status' => \App\Enums\AppointmentStatus::CANCELLED->value]);
        $fresh = $booking->fresh()->load(['organization', 'service', 'staff', 'branch', 'review', 'payments', 'customer']);
        $notifier->sendForCancellation($fresh);

        return response()->json(['data' => $this->present($fresh)]);
    }

    public function slots(Request $request, string $appointment, SlotGenerator $slotGenerator): JsonResponse
    {
        $booking = $this->ownedBooking($request, $appointment);

        if (! $booking->isChangeable()) {
            return response()->json(['message' => 'This booking can no longer be changed.'], 422);
        }

        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $this->bindTenant($booking);
        $booking->loadMissing(['service', 'staff', 'branch']);

        return response()->json(['data' => [
            'date' => $data['date'],
            'slots' => $slotGenerator->generate($booking->service, $booking->staff, $data['date'], $booking->branch, $booking->id),
        ]]);
    }

    public function reschedule(Request $request, string $appointment, SlotGenerator $slotGenerator, AppointmentScheduler $scheduler, BookingNotifier $notifier): JsonResponse
    {
        $booking = $this->ownedBooking($request, $appointment);

        if (! $booking->isChangeable()) {
            return response()->json(['message' => 'This booking can no longer be changed.'], 422);
        }

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
        ]);

        $this->bindTenant($booking);
        $booking->loadMissing(['service', 'staff', 'branch']);

        $startTime = $scheduler->normalizeTime($data['start_time']);
        $endTime = $scheduler->deriveEndTime($data['start_time'], $booking->service->duration);

        $open = $slotGenerator->generate($booking->service, $booking->staff, $data['date'], $booking->branch, $booking->id);
        if (! in_array(substr($startTime, 0, 5), $open, true)) {
            return response()->json(['message' => 'Sorry, that time slot is no longer available.'], 422);
        }

        $booking->update(['booking_date' => $data['date'], 'start_time' => $startTime, 'end_time' => $endTime]);
        $fresh = $booking->fresh()->load(['organization', 'service', 'staff', 'branch', 'review', 'payments', 'customer']);
        $notifier->sendForReschedule($fresh);

        return response()->json(['data' => $this->present($fresh)]);
    }

    public function review(StoreReviewRequest $request, string $appointment): JsonResponse
    {
        $booking = $this->ownedBooking($request, $appointment);

        abort_unless($booking->isCompleted(), 422, 'You can only review a completed appointment.');
        abort_if(Review::where('appointment_id', $booking->id)->exists(), 409, 'This appointment has already been reviewed.');

        // Bind the org so the review's organization_id is auto-filled by the
        // BelongsToOrganization creating hook.
        $this->bindTenant($booking);
        $booking->loadMissing('customer');

        $review = Review::create([
            'appointment_id' => $booking->id,
            'staff_id' => $booking->staff_id,
            'rating' => $request->integer('rating'),
            'comment' => $request->input('comment'),
            'reviewer_name' => $booking->customer?->name ?? 'Guest',
        ]);

        return response()->json(['data' => [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'status' => $review->status,
        ]], 201);
    }

    /** Resolve an appointment scoped to the account's own rows — foreign/unknown → 404. */
    protected function ownedBooking(Request $request, string $appointment): Appointment
    {
        return Appointment::whereIn('customer_id', $this->ownedCustomerIds($request))->findOrFail($appointment);
    }

    /** Bind the booking's organization so the reused booking engine is org-scoped. */
    protected function bindTenant(Appointment $booking): void
    {
        $booking->loadMissing('organization');
        app(CurrentTenant::class)->set($booking->organization);
    }

    /** Ids of the customer rows this account owns — the isolation boundary. */
    protected function ownedCustomerIds(Request $request): \Illuminate\Support\Collection
    {
        return Customer::where('customer_account_id', $request->user()->id)->pluck('id');
    }

    /** @return array<string, mixed> */
    protected function present(Appointment $a): array
    {
        $today = now()->toDateString();
        $isUpcoming = $a->booking_date->toDateString() >= $today && $a->isChangeable();

        return [
            'id' => $a->id,
            'salon' => ['id' => $a->organization?->id, 'name' => $a->organization?->name, 'slug' => $a->organization?->slug],
            'service' => $a->service?->name,
            'staff' => $a->staff?->name,
            'branch' => $a->branch?->name,
            'booking_date' => $a->booking_date->format('Y-m-d'),
            'start_time' => substr($a->start_time, 0, 5),
            'end_time' => substr($a->end_time, 0, 5),
            'status' => $a->status->value,
            'price' => number_format((float) $a->price, 2, '.', ''),
            'amount_paid' => $a->amountPaid(),
            'balance_due' => $a->balanceDue(),
            'can_manage' => $isUpcoming,
            'can_review' => $a->isCompleted() && $a->review === null,
            'review' => $a->review ? [
                'id' => $a->review->id,
                'rating' => $a->review->rating,
                'comment' => $a->review->comment,
                'status' => $a->review->status,
                'created_at' => $a->review->created_at,
            ] : null,
        ];
    }
}
