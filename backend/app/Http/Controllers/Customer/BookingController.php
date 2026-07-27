<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
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
