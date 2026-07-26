<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StaffTimeOff\StoreStaffTimeOffRequest;
use App\Http\Resources\StaffTimeOffResource;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * One-off time off for a staff member. The {staff} route param is resolved
 * manually within the tenant (User is the auth model and carries no global
 * scope), so a foreign staff id 404s. The time-off row is additionally bound
 * within that staff member so a valid id under the wrong staff 404s.
 */
class StaffTimeOffController extends Controller
{
    public function index(string $staff): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StaffTimeOff::class);

        $member = $this->findStaffOrFail($staff);

        $timeOff = StaffTimeOff::query()
            ->where('user_id', $member->id)
            ->orderBy('start_at')
            ->get();

        return StaffTimeOffResource::collection($timeOff);
    }

    public function store(StoreStaffTimeOffRequest $request, string $staff): JsonResponse
    {
        $member = $this->findStaffOrFail($staff);

        $timeOff = StaffTimeOff::create([
            ...$request->validated(),
            'user_id' => $member->id,
        ]);

        return (new StaffTimeOffResource($timeOff))->response()->setStatusCode(201);
    }

    public function destroy(string $staff, StaffTimeOff $timeOff): Response
    {
        $member = $this->findStaffOrFail($staff);

        // 404 (not 403) when the row belongs to a different staff member, so a
        // guessed id under the wrong staff leaks nothing.
        abort_unless($timeOff->user_id === $member->id, 404);

        $this->authorize('delete', $timeOff);

        $timeOff->delete();

        return response()->noContent();
    }

    /**
     * Resolve a staff user within the current tenant or 404.
     */
    protected function findStaffOrFail(string $id): User
    {
        return User::query()
            ->where('organization_id', app(CurrentTenant::class)->id())
            ->where('role', UserRole::STAFF->value)
            ->findOrFail($id);
    }
}
