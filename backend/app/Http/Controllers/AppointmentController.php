<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Requests\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Appointment\UpdateAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Services\AppointmentScheduler;
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
    private const RELATIONS = ['customer', 'staff', 'service', 'branch'];

    public function __construct(protected AppointmentScheduler $scheduler)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $appointments = Appointment::query()
            ->with(self::RELATIONS)
            ->when($request->filled('date'), fn ($q) => $q->whereDate('booking_date', $request->query('date')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->query('staff_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->query('branch_id')))
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Service (tenant-scoped) drives the duration -> end time.
        $service = Service::findOrFail($data['service_id']);
        $startTime = $this->scheduler->normalizeTime($data['start_time']);
        $endTime = $this->scheduler->deriveEndTime($data['start_time'], $service->duration);

        // Reject overlapping bookings before creating anything, so a
        // walk-in customer is never persisted for a booking that fails.
        if ($this->scheduler->hasConflict($data['staff_id'], $data['booking_date'], $startTime, $endTime)) {
            return $this->conflictResponse();
        }

        $appointment = DB::transaction(function () use ($data, $startTime, $endTime) {
            return Appointment::create([
                'branch_id' => $data['branch_id'],
                'customer_id' => $this->resolveCustomerId($data),
                'staff_id' => $data['staff_id'],
                'service_id' => $data['service_id'],
                'booking_date' => $data['booking_date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => $data['status'] ?? AppointmentStatus::PENDING->value,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return (new AppointmentResource($appointment->load(self::RELATIONS)))
            ->response()->setStatusCode(201);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment->load(self::RELATIONS));
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse|AppointmentResource
    {
        $data = $request->validated();

        // Effective schedule after applying any partial changes.
        $staffId = $data['staff_id'] ?? $appointment->staff_id;
        $bookingDate = $data['booking_date'] ?? $appointment->booking_date->format('Y-m-d');
        $serviceId = $data['service_id'] ?? $appointment->service_id;
        $duration = Service::findOrFail($serviceId)->duration;

        $startSource = $data['start_time'] ?? $appointment->start_time;
        $startTime = $this->scheduler->normalizeTime($startSource);
        $endTime = $this->scheduler->deriveEndTime($startSource, $duration);

        // Re-check for overlaps, excluding this appointment itself.
        if ($this->scheduler->hasConflict($staffId, $bookingDate, $startTime, $endTime, $appointment->id)) {
            return $this->conflictResponse();
        }

        $data['start_time'] = $startTime;
        $data['end_time'] = $endTime;

        $appointment->update($data);

        return new AppointmentResource($appointment->fresh()->load(self::RELATIONS));
    }

    public function destroy(Appointment $appointment): Response
    {
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
