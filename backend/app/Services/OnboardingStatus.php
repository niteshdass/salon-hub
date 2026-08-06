<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Which setup steps a salon has finished.
 *
 * Every answer is DERIVED from the rows the booking engine itself reads.
 * There is deliberately no stored step counter: a counter can disagree
 * with reality (a service deleted after the wizard ran, a branch address
 * cleared in Settings), and the version that disagrees is the one the
 * owner is shown. Counting rows cannot drift.
 */
class OnboardingStatus
{
    /** Every step, in the order the wizard asks them. */
    public const STEPS = ['branch', 'services', 'staff', 'look'];

    public function __construct(protected CurrentTenant $tenant) {}

    /**
     * @return array{completed_at: ?string, branch_id: ?int, steps: array<string, bool>, next_step: string}
     */
    public function forCurrentTenant(): array
    {
        $organization = $this->tenant->get();

        // Branch and Service use BelongsToOrganization, so they are already
        // scoped to the bound tenant. User is the auth model and is not.
        $branch = Branch::query()->orderBy('id')->first();
        $settings = Setting::query()->first();

        $steps = [
            'branch' => filled($branch?->address),
            'services' => Service::query()->exists(),
            'staff' => User::query()
                ->where('organization_id', $this->tenant->id())
                ->where('role', UserRole::STAFF->value)
                ->exists(),
            'look' => filled($settings?->about) || filled($organization?->logo),
        ];

        return [
            'completed_at' => $organization?->onboarding_completed_at?->toIso8601String(),
            'branch_id' => $branch?->id,
            'steps' => $steps,
            'next_step' => $this->nextStep($steps),
        ];
    }

    /**
     * The first unfinished step, or 'done' when nothing is left. This is
     * where the wizard resumes, so an owner who quit after services is
     * asked about staff and not about anything they already answered.
     *
     * @param  array<string, bool>  $steps
     */
    protected function nextStep(array $steps): string
    {
        foreach (self::STEPS as $step) {
            if (! $steps[$step]) {
                return $step;
            }
        }

        return 'done';
    }
}
