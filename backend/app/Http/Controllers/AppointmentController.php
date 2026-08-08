<?php

namespace App\Http\Controllers;

use App\Actions\AppointmentServiceWriter;
use App\Enums\AppointmentStatus;
use App\Http\Requests\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Appointment\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Services\AppointmentScheduler;
use App\Services\BookingNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Booking engine. Appointment is auto-scoped by BelongsToOrganization,
 * so every query here — including implicit route-model binding — is
 * restricted to the current tenant (cross-tenant id yields a 404).
 *
 * End-time derivation and conflict detection live in AppointmentScheduler
 * to keep this controller thin.
 */
class AppointmentController extends Controller
{
    private const RELATIONS = ['customer', 'staff', 'lines', 'branch'];

    public function __construct(
        protected AppointmentScheduler $scheduler,
        protected AppointmentServiceWriter $writer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Appointment::class);

        // A staff member only ever sees their own schedule; a policy can
        // gate a row but cannot narrow a collection, so filter here.
        $user = $request->user();

        $appointments = Appointment::query()
            ->with(self::RELATIONS)
            ->when($user->isStaff(), fn ($q) => $q->where('staff_id', $user->id))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->query('date')))
            // The calendar loads a whole month or week at once; both bounds
            // are inclusive and either may be omitted.
            ->when($request->filled('from'), fn ($q) => $q->whereDate('booking_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('booking_date', '<=', $request->query('to')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->query('staff_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->query('branch_id')))
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request, BookingNotifier $notifier): JsonResponse
    {
        $data = $request->validated();

        // The whole visit's duration drives the block this booking occupies.
        $totals = $this->writer->totalsFor($data['service_ids']);
        $startTime = $this->scheduler->normalizeTime($data['start_time']);
        $endTime = $this->scheduler->deriveEndTime($data['start_time'], $totals['duration']);

        // Reject overlapping bookings before creating anything, so a
        // walk-in customer is never persisted for a booking that fails.
        if ($this->scheduler->hasConflict($data['staff_id'], $data['booking_date'], $startTime, $endTime)) {
            return $this->conflictResponse();
        }

        $appointment = DB::transaction(function () use ($data, $startTime, $endTime) {
            $appointment = Appointment::create([
                'branch_id' => $data['branch_id'],
                'customer_id' => $this->resolveCustomerId($data),
                'staff_id' => $data['staff_id'],
                'booking_date' => $data['booking_date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'price' => 0,
                'status' => $data['status'] ?? AppointmentStatus::PENDING->value,
                'notes' => $data['notes'] ?? null,
            ]);

            // Freezes each line at today's menu price and writes back the
            // appointment's own total and end time.
            $this->writer->sync($appointment, $data['service_ids']);

            return $appointment->fresh();
        });

        $appointment->load(self::RELATIONS);

        $notifier->sendForNewBooking($appointment);

        return (new AppointmentResource($appointment))
            ->response()->setStatusCode(201);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        $this->authorize('view', $appointment);

        return new AppointmentResource($appointment->load(self::RELATIONS));
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse|AppointmentResource
    {
        $data = $request->validated();

        // Effective schedule after applying any partial changes.
        $staffId = $data['staff_id'] ?? $appointment->staff_id;
        $bookingDate = $data['booking_date'] ?? $appointment->booking_date->format('Y-m-d');
        $serviceIds = $data['service_ids'] ?? $appointment->lines->pluck('service_id')->filter()->all();
        $duration = $this->writer->totalsFor($serviceIds)['duration'];

        $startSource = $data['start_time'] ?? $appointment->start_time;
        $startTime = $this->scheduler->normalizeTime($startSource);
        $endTime = $this->scheduler->deriveEndTime($startSource, $duration);

        // Re-check for overlaps, excluding this appointment itself.
        if ($this->scheduler->hasConflict($staffId, $bookingDate, $startTime, $endTime, $appointment->id)) {
            return $this->conflictResponse();
        }

        $data['start_time'] = $startTime;
        $data['end_time'] = $endTime;
        unset($data['service_ids']);

        $appointment = DB::transaction(function () use ($appointment, $data, $serviceIds) {
            $appointment->update($data);
            $this->writer->sync($appointment, $serviceIds);

            return $appointment;
        });

        return new AppointmentResource($appointment->fresh()->load(self::RELATIONS));
    }

    public function destroy(Appointment $appointment): Response
    {
        $this->authorize('delete', $appointment);

        $appointment->delete();

        return response()->noContent();
    }

    /**
     * Resolve the customer for a booking: an existing customer_id wins;
     * otherwise create an inline walk-in customer (org auto-filled).
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCustomerId(array $data): int
    {
        if (! empty($data['customer_id'])) {
            return (int) $data['customer_id'];
        }

        return Customer::create([
            'name' => $data['new_customer']['name'],
            'phone' => $data['new_customer']['phone'] ?? null,
            'email' => $data['new_customer']['email'] ?? null,
        ])->id;
    }

    protected function conflictResponse(): JsonResponse
    {
        return response()->json(
            ['message' => 'This staff member is already booked for an overlapping time slot.'],
            422,
        );
    }
}
