<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Everything the dashboard needs in one request. All appointment queries
 * run through `scopedAppointments()`, so a staff member's dashboard is
 * their own schedule rather than the salon's.
 */
class DashboardController extends Controller
{
    private const UPCOMING_LIMIT = 5;

    public function __invoke(Request $request, CurrentTenant $tenant): JsonResponse
    {
        $user = $request->user();

        // "Today" is the salon's today, not the server's.
        $now = Carbon::now($tenant->get()?->timezone ?: config('app.timezone'));
        $today = $now->toDateString();

        $payload = [
            'today' => [
                'date' => $today,
                'bookings' => $this->scopedAppointments($user)->whereDate('booking_date', $today)->count(),
                'by_status' => $this->statusBreakdown($user, $today),
            ],
            'totals' => [
                'branches' => Branch::count(),
                'services' => Service::count(),
                'customers' => Customer::count(),
                'staff' => User::where('organization_id', $tenant->id())->count(),
            ],
            'upcoming' => AppointmentResource::collection($this->upcoming($user, $now)),
        ];

        // Takings are the owner's and manager's business, not a stylist's.
        if ($user->isManagerOrOwner()) {
            $payload['today']['revenue'] = $this->revenueFor($user, $today);
        }

        return response()->json($payload);
    }

    /**
     * Base appointment query for whoever is asking. The tenant scope is
     * applied globally; this narrows it further for staff.
     */
    protected function scopedAppointments(User $user): Builder
    {
        return Appointment::query()
            ->when($user->isStaff(), fn ($q) => $q->where('staff_id', $user->id));
    }

    /**
     * Every status, zero-filled — the UI renders a fixed row of chips and
     * should not have to guess which keys came back.
     *
     * @return array<string, int>
     */
    protected function statusBreakdown(User $user, string $today): array
    {
        $counts = $this->scopedAppointments($user)
            ->whereDate('booking_date', $today)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $breakdown = [];

        foreach (AppointmentStatus::cases() as $status) {
            $breakdown[$status->value] = (int) $counts->get($status->value, 0);
        }

        return $breakdown;
    }

    /**
     * Money earned today: a booking earns once it is marked completed, at
     * the price snapshotted onto it when it was booked.
     */
    protected function revenueFor(User $user, string $today): float
    {
        // The price frozen on the booking, not a menu price that may have
        // changed since — a completed booking earns exactly what it quoted.
        $total = $this->scopedAppointments($user)
            ->whereDate('booking_date', $today)
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->sum('price');

        return round((float) $total, 2);
    }

    /**
     * The next few appointments from this moment on. Cancelled ones are
     * dropped — nobody needs to be reminded of a booking that is off.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Appointment>
     */
    protected function upcoming(User $user, Carbon $now): mixed
    {
        $today = $now->toDateString();

        return $this->scopedAppointments($user)
            ->with(['customer', 'staff', 'service', 'branch'])
            ->where('status', '!=', AppointmentStatus::CANCELLED->value)
            ->where(function ($q) use ($today, $now) {
                $q->whereDate('booking_date', '>', $today)
                    ->orWhere(fn ($q) => $q->whereDate('booking_date', $today)
                        ->where('start_time', '>=', $now->format('H:i:s')));
            })
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->limit(self::UPCOMING_LIMIT)
            ->get();
    }
}
