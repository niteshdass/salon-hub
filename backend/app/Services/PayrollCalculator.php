<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PayType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;

/**
 * Turns a month into one pay line per staff member. Pure: it reads, it
 * computes, it writes nothing.
 *
 * The revenue base is deliberately identical to ReportService::earnedWindow —
 * completed appointments at their snapshot price — so payroll and the revenue
 * report can never disagree about what a stylist brought in.
 */
class PayrollCalculator
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function linesFor(CarbonInterface $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        // User carries no tenant global scope (it is the auth model), so the
        // organization filter is explicit here.
        $staff = User::query()
            ->where('organization_id', app(CurrentTenant::class)->id())
            ->where('role', UserRole::STAFF->value)
            ->with('staffProfile')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $member) => ($member->staffProfile?->pay_type ?? PayType::NONE) !== PayType::NONE);

        $revenue = Appointment::query()
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('booking_date', '>=', $start)
            ->whereDate('booking_date', '<=', $end)
            ->selectRaw('staff_id, COUNT(*) as bookings, SUM(price) as earned')
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        return $staff->map(function (User $member) use ($revenue) {
            $profile = $member->staffProfile;
            $payType = $profile->pay_type;
            $row = $revenue->get($member->id);

            $earned = round((float) ($row->earned ?? 0), 2);
            $rate = (float) $profile->commission_rate;
            $salary = $payType->paysSalary() ? round((float) $profile->monthly_salary, 2) : 0.0;
            $commission = $payType->paysCommission() ? round($earned * $rate / 100, 2) : 0.0;

            return [
                'staff_id' => $member->id,
                'staff_name' => $member->name,
                'pay_type' => $payType->value,
                'commission_rate' => $rate,
                'monthly_salary' => round((float) $profile->monthly_salary, 2),
                'earned_revenue' => $earned,
                'bookings' => (int) ($row->bookings ?? 0),
                'salary_amount' => $salary,
                'commission_amount' => $commission,
                'total_amount' => round($salary + $commission, 2),
            ];
        })->values()->all();
    }
}
