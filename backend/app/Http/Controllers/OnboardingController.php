<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\OnboardingStatus;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Guided first-run setup. Owner-only: a manager or staff member joins a
 * salon someone else has already configured.
 */
class OnboardingController extends Controller
{
    public function __construct(
        protected OnboardingStatus $status,
        protected CurrentTenant $tenant,
    ) {}

    public function status(): JsonResponse
    {
        $this->authorize('update', Organization::class);

        return response()->json(['data' => $this->status->forCurrentTenant()]);
    }

    /**
     * Stop asking. Called when the owner finishes the wizard or dismisses
     * the dashboard card.
     *
     * Idempotent, and deliberately does not re-stamp: the timestamp
     * answers "when did they stop being new", and a second call from a
     * double-tapped button must not move that.
     */
    public function complete(): JsonResponse
    {
        $this->authorize('update', Organization::class);

        $organization = $this->tenant->get();

        if ($organization->onboarding_completed_at === null) {
            $organization->onboarding_completed_at = now();
            $organization->save();
        }

        return response()->json(['data' => $this->status->forCurrentTenant()]);
    }
}
