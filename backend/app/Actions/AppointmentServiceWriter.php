<?php

namespace App\Actions;

use App\Models\Appointment;
use App\Models\Service;
use App\Services\AppointmentScheduler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * The single definition of what a visit costs and how long it takes.
 *
 * Public booking, dashboard create, and dashboard edit all write lines
 * through here, so the three paths cannot drift on how a total is computed.
 */
class AppointmentServiceWriter
{
    public function __construct(protected AppointmentScheduler $scheduler) {}

    /**
     * Replace the appointment's lines with fresh snapshots of the given
     * services, then write back the derived total and end time.
     *
     * @param  list<int>  $serviceIds  in the order the customer picked them
     */
    public function sync(Appointment $appointment, array $serviceIds): void
    {
        $totals = $this->totalsFor($serviceIds);

        $appointment->lines()->delete();

        foreach ($totals['services']->values() as $index => $service) {
            $appointment->lines()->create([
                'service_id' => $service->id,
                'name' => $service->name,
                'price' => $service->price,
                'duration' => $service->duration,
                'sort_order' => $index,
            ]);
        }

        $appointment->forceFill([
            'price' => $totals['price'],
            'end_time' => $this->scheduler->deriveEndTime(
                $appointment->start_time,
                $totals['duration'],
            ),
        ])->save();

        $appointment->unsetRelation('lines');
    }

    /**
     * Duration, price, and the services themselves for a set of ids, in the
     * order given. Callers that need the duration before an appointment row
     * exists (conflict checks, slot lookups) use this.
     *
     * Service is tenant-scoped, so an id belonging to another salon simply
     * does not resolve — hence the explicit miss check rather than a filter.
     *
     * @param  list<int>  $serviceIds
     * @return array{duration: int, price: float, services: Collection<int, Service>}
     */
    public function totalsFor(array $serviceIds): array
    {
        $found = Service::query()->findMany($serviceIds)->keyBy('id');

        $services = collect($serviceIds)->map(function ($id) use ($found): Service {
            $service = $found->get((int) $id);

            if ($service === null) {
                throw (new ModelNotFoundException)->setModel(Service::class, [$id]);
            }

            return $service;
        });

        return [
            'duration' => (int) $services->sum('duration'),
            'price' => round((float) $services->sum(fn (Service $s) => (float) $s->price), 2),
            'services' => $services,
        ];
    }
}
