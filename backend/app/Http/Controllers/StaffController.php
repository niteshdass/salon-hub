<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use App\Services\PlanLimit;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff = User(role=staff) + StaffProfile.
 *
 * User is NOT auto-scoped by BelongsToOrganization (it is the auth
 * model), so EVERY query is scoped manually by organization_id + role.
 */
class StaffController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $staff = $this->baseQuery()
            ->with(['staffProfile', 'services'])
            ->latest('id')
            ->get();

        return StaffResource::collection($staff);
    }

    public function store(StoreStaffRequest $request, PlanLimit $planLimit): JsonResponse
    {
        if (! $planLimit->canAddStaff()) {
            return response()->json(['message' => 'Your free plan allows only 10 staff.'], 422);
        }

        $data = $request->validated();
        $tenantId = app(CurrentTenant::class)->id();

        $user = DB::transaction(function () use ($data, $tenantId) {
            $user = User::create([
                'organization_id' => $tenantId,
                'name' => $data['name'],
                'email' => $data['email'] ?? $this->placeholderEmail(),
                'password' => Hash::make($data['password'] ?? Str::password(12)),
                'role' => UserRole::STAFF->value,
                'status' => UserStatus::ACTIVE->value,
            ]);

            $user->staffProfile()->create([
                'phone' => $data['phone'] ?? null,
                'designation' => $data['designation'] ?? null,
                'bio' => $data['bio'] ?? null,
                'profile_image' => $data['profile_image'] ?? null,
                'working_days_json' => $data['working_days_json'] ?? null,
                'working_hours_json' => $data['working_hours_json'] ?? null,
            ]);

            if (! empty($data['service_ids'])) {
                $user->services()->sync($data['service_ids']);
            }

            return $user;
        });

        $user->load(['staffProfile', 'services']);

        return (new StaffResource($user))->response()->setStatusCode(201);
    }

    public function show(string $staff): StaffResource
    {
        $this->authorize('view', User::class);

        $user = $this->findStaffOrFail($staff);

        return new StaffResource($user->load(['staffProfile', 'services']));
    }

    public function update(UpdateStaffRequest $request, string $staff): StaffResource
    {
        $user = $this->findStaffOrFail($staff);
        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            $userUpdate = [];
            foreach (['name', 'email'] as $field) {
                if (array_key_exists($field, $data)) {
                    $userUpdate[$field] = $data[$field];
                }
            }
            if (! empty($data['password'])) {
                $userUpdate['password'] = Hash::make($data['password']);
            }
            if ($userUpdate !== []) {
                $user->update($userUpdate);
            }

            $profileData = [];
            foreach (['phone', 'designation', 'bio', 'profile_image', 'working_days_json', 'working_hours_json'] as $field) {
                if (array_key_exists($field, $data)) {
                    $profileData[$field] = $data[$field];
                }
            }
            if ($profileData !== []) {
                $user->staffProfile()->updateOrCreate(['user_id' => $user->id], $profileData);
            }

            if (array_key_exists('service_ids', $data)) {
                $user->services()->sync($data['service_ids'] ?? []);
            }
        });

        return new StaffResource($user->fresh()->load(['staffProfile', 'services']));
    }

    /**
     * The explicit detach/delete below covers staff_services and
     * staff_profiles, but appointments.staff_id is also `cascadeOnDelete`
     * (and payments cascade from appointments), so removing a stylist would
     * delete every appointment they ever performed and every payment against
     * those appointments — silently, irreversibly, and taking the salon's
     * revenue history with it. Refuse while any remain; deactivating the
     * staff member (status) is the reversible action.
     */
    public function destroy(string $staff): Response|JsonResponse
    {
        $this->authorize('delete', User::class);

        $user = $this->findStaffOrFail($staff);

        if ($user->appointments()->exists()) {
            return response()->json([
                'message' => 'This staff member has appointments booked against them and cannot be deleted.',
            ], 422);
        }

        DB::transaction(function () use ($user) {
            // FKs cascade (staff_profiles + staff_services), but detach /
            // delete explicitly so it is robust regardless of FK enforcement.
            $user->services()->detach();
            $user->staffProfile()->delete();
            $user->delete();
        });

        return response()->noContent();
    }

    /**
     * Base tenant-scoped query for staff users.
     */
    protected function baseQuery(): Builder
    {
        return User::query()
            ->where('organization_id', app(CurrentTenant::class)->id())
            ->where('role', UserRole::STAFF->value);
    }

    /**
     * Resolve a staff user within the current tenant or 404.
     */
    protected function findStaffOrFail(string $id): User
    {
        return $this->baseQuery()->findOrFail($id);
    }

    /**
     * An address for a staff member who has none.
     *
     * `.invalid` is reserved by RFC 2606 and is guaranteed never to resolve,
     * so a placeholder can never deliver mail to a real stranger who happens
     * to own the domain we would otherwise have invented. The row still
     * cannot be signed into: the password is the random one store() already
     * generates and is never shown to anyone, and no verification mail is
     * sent.
     */
    protected function placeholderEmail(): string
    {
        $slug = app(CurrentTenant::class)->get()?->slug ?? 'salon';

        return 'staff-'.Str::lower(Str::random(10)).'@'.$slug.'.invalid';
    }
}
