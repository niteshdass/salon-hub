<?php

namespace Tests\Feature\Finance;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Organization;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class FinanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected function makeOrg(string $slug = 'acme'): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);
    }

    protected function makeUser(Organization $org, string $role): User
    {
        return User::create([
            'organization_id' => $org->id,
            'name' => ucfirst($role),
            'email' => Str::random(6)."@{$org->slug}.test",
            'password' => 'secret1234',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    protected function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    /**
     * A staff user plus profile. $pay overrides pay_type / monthly_salary /
     * commission_rate; the default is an unpaid ('none') rule.
     *
     * @param  array<string, mixed>  $pay
     */
    protected function makeStaff(Organization $org, array $pay = [], string $name = 'Sam Stylist'): User
    {
        $staff = User::create([
            'organization_id' => $org->id,
            'name' => $name,
            'email' => Str::random(6)."@{$org->slug}.test",
            'password' => 'secret1234',
            'role' => 'staff',
            'status' => 'active',
        ]);

        StaffProfile::create([
            'user_id' => $staff->id,
            'designation' => 'Stylist',
            'pay_type' => $pay['pay_type'] ?? 'none',
            'monthly_salary' => $pay['monthly_salary'] ?? 0,
            'commission_rate' => $pay['commission_rate'] ?? 0,
        ]);

        return $staff;
    }

    /**
     * A hand-logged expense: no payroll run behind it, so nothing here is
     * locked. Overrides: branch_id, category, expense_date, amount, note.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeExpense(Organization $org, array $overrides = []): Expense
    {
        return Expense::create([
            'organization_id' => $org->id,
            'branch_id' => $overrides['branch_id'] ?? null,
            'category' => $overrides['category'] ?? 'supplies',
            'expense_date' => $overrides['expense_date'] ?? '2026-07-10',
            'amount' => $overrides['amount'] ?? 50,
            'note' => $overrides['note'] ?? null,
        ]);
    }

    protected function makeService(Organization $org, float $price = 25): Service
    {
        return Service::create([
            'organization_id' => $org->id,
            'name' => 'Haircut',
            'duration' => 30,
            'price' => $price,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides  staff, date, price, status
     */
    protected function makeAppointment(Organization $org, array $overrides = []): Appointment
    {
        $branch = $overrides['branch'] ?? Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = $overrides['service'] ?? $this->makeService($org);
        $staff = $overrides['staff'] ?? $this->makeStaff($org);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Casey Customer']);

        return Appointment::create([
            'organization_id' => $org->id,
            'public_token' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'booking_date' => $overrides['date'] ?? '2026-07-15',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'price' => $overrides['price'] ?? 25,
            'status' => $overrides['status'] ?? 'completed',
        ]);
    }
}
