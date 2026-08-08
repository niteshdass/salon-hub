# Staff Compensation & Expenses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a salon owner record how each staff member is paid (commission, fixed salary, or both), close a monthly payroll run, log other expenses, and see net profit.

**Architecture:** Three new columns on `staff_profiles` hold the pay rule. A `PayrollCalculator` service applies that rule to completed-appointment revenue for a month and returns lines; a `payroll_runs` + `payroll_lines` pair stores them, snapshotting the rule so history survives a raise. Finalizing a run writes one row into a new `expenses` table, which also holds rent/supplies/etc. The existing `/api/reports` endpoint gains a `profit` block that subtracts expenses from earned revenue. Everything finance-related is owner-only.

**Tech Stack:** Laravel 12 / PHP 8.4, PHPUnit, SQLite in tests / MySQL in production. Vue 3 `<script setup>`, Pinia, Vue Router, Tailwind 4, Vitest.

**Spec:** [`docs/superpowers/specs/2026-08-08-staff-compensation-expenses-design.md`](../specs/2026-08-08-staff-compensation-expenses-design.md)

## Global Constraints

- Money columns are `decimal(10,2)`; percentage columns are `decimal(5,2)`. All computed money is `round(..., 2)`.
- Every tenant-owned model uses `App\Models\Concerns\BelongsToOrganization`. Never filter by `organization_id` by hand on those models — the global scope does it. `User` is the exception (no scope; filter manually by `organization_id` **and** `role`).
- Enums live in `app/Enums`, are string-backed, and are used instead of magic strings in casts, validation (`Rule::enum`), and comparisons.
- Controllers stay thin: validation in Form Requests, authorization in Policies (auto-discovered by name — no manual registration), business logic in `app/Services`, output through API Resources.
- Every new finance route is owner-only, reads included. Managers and staff get 403.
- Revenue for commission is always `SUM(appointments.price) WHERE status = 'completed'` over `booking_date` — the same predicate `ReportService::earnedWindow()` uses.
- Backend tests run from `backend/`: `php artisan test`. Frontend tests run from `frontend/`: `npm run test:unit`.
- `parseApiError(err, fallback)` returns an **object** `{ message, errors }` — never assign it straight to a display string. Use `.message` for banners and `.errors` for per-field messages, as `StaffView.vue` does.
- `Modal.vue` takes `title` and `size` props and emits `close`; it has no `open` prop — the parent controls visibility with `v-if`, and buttons go in the `#footer` slot.
- Migration filenames use the `2026_08_08_HHMMSS_` prefix pattern already in `database/migrations`.
- Commit after every task with a Conventional Commits message.

---

### Task 1: Pay rule on staff

**Files:**
- Create: `backend/app/Enums/PayType.php`
- Create: `backend/database/migrations/2026_08_08_100000_add_pay_rule_to_staff_profiles_table.php`
- Create: `backend/tests/Feature/Finance/FinanceTestCase.php`
- Create: `backend/tests/Feature/Finance/StaffPayRuleTest.php`
- Modify: `backend/app/Models/StaffProfile.php`
- Modify: `backend/app/Http/Requests/Staff/StoreStaffRequest.php`
- Modify: `backend/app/Http/Requests/Staff/UpdateStaffRequest.php`
- Modify: `backend/app/Http/Controllers/StaffController.php`
- Modify: `backend/app/Http/Resources/StaffResource.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Enums\PayType` with cases `NONE|COMMISSION|SALARY|HYBRID` and methods `paysSalary(): bool`, `paysCommission(): bool`. `StaffProfile` gains `pay_type` (cast to `PayType`), `monthly_salary`, `commission_rate`. `Tests\Feature\Finance\FinanceTestCase` with helpers `makeOrg(string $slug = 'acme'): Organization`, `makeUser(Organization $org, string $role): User`, `token(User $user): string`, `makeStaff(Organization $org, array $pay = [], string $name = 'Sam Stylist'): User`, `makeService(Organization $org, float $price = 25): Service`, `makeAppointment(Organization $org, array $overrides = []): Appointment`.

- [ ] **Step 1: Write the shared test base**

Create `backend/tests/Feature/Finance/FinanceTestCase.php`. Every later finance test extends this. It mirrors the helper style already in `tests/Feature/Reports/ReportsTest.php` (models are created explicitly, not through factories, because `Organization` and `User` carry required columns the factories do not all set).

```php
<?php

namespace Tests\Feature\Finance;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
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
            'service_id' => $service->id,
            'booking_date' => $overrides['date'] ?? '2026-07-15',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'price' => $overrides['price'] ?? 25,
            'status' => $overrides['status'] ?? 'completed',
        ]);
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `backend/tests/Feature/Finance/StaffPayRuleTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Models\StaffProfile;

class StaffPayRuleTest extends FinanceTestCase
{
    public function test_owner_can_set_a_hybrid_pay_rule_on_create(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))->postJson('/api/staff', [
            'name' => 'Rima',
            'email' => 'rima@acme.test',
            'pay_type' => 'hybrid',
            'monthly_salary' => 1000,
            'commission_rate' => 25,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.pay_type', 'hybrid');
        $this->assertSame('1000.00', StaffProfile::firstWhere('user_id', $res->json('data.id'))->monthly_salary);
        $this->assertSame('25.00', StaffProfile::firstWhere('user_id', $res->json('data.id'))->commission_rate);
    }

    public function test_pay_type_defaults_to_none(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))
            ->postJson('/api/staff', ['name' => 'Rima', 'email' => 'rima@acme.test']);

        $res->assertCreated();
        $res->assertJsonPath('data.pay_type', 'none');
    }

    public function test_owner_can_update_a_pay_rule(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $staff = $this->makeStaff($org);

        $this->withToken($this->token($owner))
            ->patchJson("/api/staff/{$staff->id}", ['pay_type' => 'commission', 'commission_rate' => 30])
            ->assertOk()
            ->assertJsonPath('data.commission_rate', '30.00');
    }

    public function test_commission_rate_over_100_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $staff = $this->makeStaff($org);

        $this->withToken($this->token($owner))
            ->patchJson("/api/staff/{$staff->id}", ['pay_type' => 'commission', 'commission_rate' => 101])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commission_rate');
    }

    public function test_manager_cannot_see_pay_fields(): void
    {
        $org = $this->makeOrg();
        $manager = $this->makeUser($org, 'manager');
        $staff = $this->makeStaff($org, ['pay_type' => 'salary', 'monthly_salary' => 900]);

        $res = $this->withToken($this->token($manager))->getJson("/api/staff/{$staff->id}");

        $res->assertOk();
        $res->assertJsonMissingPath('data.pay_type');
        $res->assertJsonMissingPath('data.monthly_salary');
        $res->assertJsonMissingPath('data.commission_rate');
    }

    public function test_manager_writing_pay_fields_is_ignored_not_rejected(): void
    {
        $org = $this->makeOrg();
        $manager = $this->makeUser($org, 'manager');
        $staff = $this->makeStaff($org);

        $this->withToken($this->token($manager))
            ->patchJson("/api/staff/{$staff->id}", ['designation' => 'Senior', 'monthly_salary' => 5000])
            ->assertOk();

        $profile = StaffProfile::firstWhere('user_id', $staff->id);
        $this->assertSame('Senior', $profile->designation);
        $this->assertSame('0.00', $profile->monthly_salary);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=StaffPayRuleTest`
Expected: FAIL — no `pay_type` column, unknown JSON path.

- [ ] **Step 4: Create the PayType enum**

`backend/app/Enums/PayType.php`:

```php
<?php

namespace App\Enums;

/**
 * How a staff member is paid. `none` means the salon settles with them
 * outside the app — those staff are skipped by payroll entirely.
 */
enum PayType: string
{
    case NONE = 'none';
    case COMMISSION = 'commission';
    case SALARY = 'salary';
    case HYBRID = 'hybrid';

    public function paysSalary(): bool
    {
        return in_array($this, [self::SALARY, self::HYBRID], true);
    }

    public function paysCommission(): bool
    {
        return in_array($this, [self::COMMISSION, self::HYBRID], true);
    }
}
```

- [ ] **Step 5: Write the migration**

`backend/database/migrations/2026_08_08_100000_add_pay_rule_to_staff_profiles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How this staff member is paid. Existing staff default to 'none' —
     * the salon has not told us their deal yet, and payroll skips them
     * rather than inventing a number.
     */
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->string('pay_type')->default('none')->after('working_hours_json');
            $table->decimal('monthly_salary', 10, 2)->default(0)->after('pay_type');
            $table->decimal('commission_rate', 5, 2)->default(0)->after('monthly_salary');
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropColumn(['pay_type', 'monthly_salary', 'commission_rate']);
        });
    }
};
```

- [ ] **Step 6: Update the StaffProfile model**

In `backend/app/Models/StaffProfile.php`, add the three fields to `$fillable` (after `working_hours_json`) and add casts. The final `casts()` body:

```php
    protected function casts(): array
    {
        return [
            'working_days_json' => 'array',
            'working_hours_json' => 'array',
            'pay_type' => PayType::class,
            'monthly_salary' => 'decimal:2',
            'commission_rate' => 'decimal:2',
        ];
    }
```

Add `use App\Enums\PayType;` to the imports.

- [ ] **Step 7: Gate the fields in the Form Requests**

In both `StoreStaffRequest` and `UpdateStaffRequest`, add these rules to the `rules()` array:

```php
            'pay_type' => ['nullable', Rule::enum(PayType::class)],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
```

And add this method to both classes — a non-owner's pay fields are dropped, not rejected, so a manager saving an unrelated edit still succeeds:

```php
    /**
     * Pay is owner-only. A manager's request keeps working; the pay fields
     * are simply removed before validation ever sees them.
     */
    protected function prepareForValidation(): void
    {
        if (! ($this->user()?->isOwner() ?? false)) {
            $this->replace(Arr::except($this->all(), ['pay_type', 'monthly_salary', 'commission_rate']));
        }
    }
```

Add `use App\Enums\PayType;`, `use Illuminate\Support\Arr;` to both. `StoreStaffRequest` already imports `Rule`; if not, add `use Illuminate\Validation\Rule;`.

- [ ] **Step 8: Persist the fields in StaffController**

In `store()`, the `staffProfile()->create([...])` array gains:

```php
                'pay_type' => $data['pay_type'] ?? PayType::NONE->value,
                'monthly_salary' => $data['monthly_salary'] ?? 0,
                'commission_rate' => $data['commission_rate'] ?? 0,
```

In `update()`, extend the profile field list:

```php
            foreach (['phone', 'designation', 'bio', 'profile_image', 'working_days_json', 'working_hours_json', 'pay_type', 'monthly_salary', 'commission_rate'] as $field) {
```

Add `use App\Enums\PayType;` to the imports.

- [ ] **Step 9: Expose the fields to owners only in StaffResource**

In `backend/app/Http/Resources/StaffResource.php`, above the return add:

```php
        // Pay is the most sensitive field on a staff record: owners only.
        $isOwner = $request->user()?->isOwner() ?? false;
```

and inside the returned array, after `'working_hours_json'`:

```php
            'pay_type' => $this->when($isOwner, fn () => ($profile?->pay_type ?? PayType::NONE)->value),
            'monthly_salary' => $this->when($isOwner, fn () => $profile?->monthly_salary ?? '0.00'),
            'commission_rate' => $this->when($isOwner, fn () => $profile?->commission_rate ?? '0.00'),
```

Add `use App\Enums\PayType;`.

- [ ] **Step 10: Run the tests**

Run: `cd backend && php artisan test --filter=StaffPayRuleTest`
Expected: PASS (6 tests).

- [ ] **Step 11: Run the full suite for regressions**

Run: `cd backend && php artisan test`
Expected: PASS — existing staff tests still green.

- [ ] **Step 12: Commit**

```bash
cd backend && git add app/Enums/PayType.php app/Models/StaffProfile.php app/Http/Requests/Staff app/Http/Controllers/StaffController.php app/Http/Resources/StaffResource.php database/migrations/2026_08_08_100000_add_pay_rule_to_staff_profiles_table.php tests/Feature/Finance
git commit -m "feat(finance): record how each staff member is paid"
```

---

### Task 2: Expense log

**Files:**
- Create: `backend/app/Enums/ExpenseCategory.php`
- Create: `backend/database/migrations/2026_08_08_100100_create_expenses_table.php`
- Create: `backend/app/Models/Expense.php`
- Create: `backend/app/Policies/ExpensePolicy.php`
- Create: `backend/app/Http/Requests/Expense/StoreExpenseRequest.php`
- Create: `backend/app/Http/Requests/Expense/UpdateExpenseRequest.php`
- Create: `backend/app/Http/Resources/ExpenseResource.php`
- Create: `backend/app/Http/Controllers/ExpenseController.php`
- Create: `backend/tests/Feature/Finance/ExpenseTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: `Tests\Feature\Finance\FinanceTestCase` (Task 1).
- Produces: `App\Enums\ExpenseCategory` (cases `RENT, UTILITIES, SUPPLIES, SALARY, MARKETING, EQUIPMENT, MAINTENANCE, OTHER`). `App\Models\Expense` with `$fillable = [organization_id, branch_id, payroll_run_id, category, expense_date, amount, note, recorded_by]`, casts `category => ExpenseCategory`, `expense_date => 'date'`, `amount => 'decimal:2'`, and `isSystemGenerated(): bool`. Routes `GET|POST /api/expenses`, `PATCH|DELETE /api/expenses/{expense}`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Finance/ExpenseTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;

class ExpenseTest extends FinanceTestCase
{
    public function test_owner_can_log_an_expense(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $res = $this->withToken($this->token($owner))->postJson('/api/expenses', [
            'category' => 'rent',
            'expense_date' => '2026-07-01',
            'amount' => 450.50,
            'note' => 'July rent',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.category', 'rent');
        $res->assertJsonPath('data.amount', '450.50');
        $this->assertSame($owner->id, Expense::first()->recorded_by);
    }

    public function test_expenses_are_listed_newest_first_within_the_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $this->expense($org, ['expense_date' => '2026-07-01', 'amount' => 100]);
        $this->expense($org, ['expense_date' => '2026-07-20', 'amount' => 200]);
        $this->expense($org, ['expense_date' => '2026-06-01', 'amount' => 300]);

        $res = $this->withToken($this->token($owner))
            ->getJson('/api/expenses?from=2026-07-01&to=2026-07-31');

        $res->assertOk();
        $res->assertJsonCount(2, 'data');
        $res->assertJsonPath('data.0.amount', '200.00');
    }

    public function test_a_zero_amount_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->postJson('/api/expenses', ['category' => 'rent', 'expense_date' => '2026-07-01', 'amount' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_future_dated_expense_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->postJson('/api/expenses', [
                'category' => 'rent',
                'expense_date' => now()->addDay()->toDateString(),
                'amount' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expense_date');
    }

    public function test_owner_can_update_and_delete_an_expense(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $expense = $this->expense($org, ['amount' => 100]);
        $token = $this->token($owner);

        $this->withToken($token)
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 125])
            ->assertOk()
            ->assertJsonPath('data.amount', '125.00');

        $this->withToken($token)->deleteJson("/api/expenses/{$expense->id}")->assertNoContent();
        $this->assertSame(0, Expense::count());
    }

    public function test_manager_and_staff_cannot_touch_expenses(): void
    {
        $org = $this->makeOrg();
        $expense = $this->expense($org);

        foreach (['manager', 'staff'] as $role) {
            $token = $this->token($this->makeUser($org, $role));
            $this->withToken($token)->getJson('/api/expenses')->assertForbidden();
            $this->withToken($token)->postJson('/api/expenses', [
                'category' => 'rent', 'expense_date' => '2026-07-01', 'amount' => 10,
            ])->assertForbidden();
            $this->withToken($token)->deleteJson("/api/expenses/{$expense->id}")->assertForbidden();
        }
    }

    public function test_another_tenants_expense_is_not_found(): void
    {
        $org = $this->makeOrg();
        $other = $this->makeOrg('other');
        $owner = $this->makeUser($org, 'owner');
        $theirs = $this->expense($other);

        $this->withToken($this->token($owner))
            ->patchJson("/api/expenses/{$theirs->id}", ['amount' => 1])
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function expense(\App\Models\Organization $org, array $overrides = []): Expense
    {
        return Expense::create([
            'organization_id' => $org->id,
            'category' => $overrides['category'] ?? 'supplies',
            'expense_date' => $overrides['expense_date'] ?? '2026-07-10',
            'amount' => $overrides['amount'] ?? 50,
            'note' => $overrides['note'] ?? null,
        ]);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=ExpenseTest`
Expected: FAIL — `Class "App\Models\Expense" not found`.

- [ ] **Step 3: Create the enum and migration**

`backend/app/Enums/ExpenseCategory.php`:

```php
<?php

namespace App\Enums;

/**
 * A fixed list, not an owner-defined one: report grouping stays stable and
 * `salary` keeps a reserved meaning (payroll writes it).
 */
enum ExpenseCategory: string
{
    case RENT = 'rent';
    case UTILITIES = 'utilities';
    case SUPPLIES = 'supplies';
    case SALARY = 'salary';
    case MARKETING = 'marketing';
    case EQUIPMENT = 'equipment';
    case MAINTENANCE = 'maintenance';
    case OTHER = 'other';
}
```

`backend/database/migrations/2026_08_08_100100_create_expenses_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything the salon spends. `payroll_run_id` is set only on the one
     * salary row a finalized payroll run creates: it stops the P&L counting
     * staff pay twice, and the cascade means deleting a run takes its
     * salary expense with it.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payroll_run_id')->nullable();
            // Who keyed it in. nullOnDelete so removing a staff account never
            // erases the money record they entered.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category');
            $table->date('expense_date');
            $table->decimal('amount', 10, 2);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
```

`payroll_run_id` is a plain column here and gains its foreign key in Task 3, when `payroll_runs` exists.

- [ ] **Step 4: Create the model, policy, requests, resource, controller**

`backend/app/Models/Expense.php`:

```php
<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'payroll_run_id',
        'recorded_by',
        'category',
        'expense_date',
        'amount',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'expense_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /** Written by a finalized payroll run, so not editable on its own. */
    public function isSystemGenerated(): bool
    {
        return $this->payroll_run_id !== null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
```

`backend/app/Policies/ExpensePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * What the salon spends — including every salary — is owner-only, reads
 * included. A manager who can read the expense log can read the payroll.
 * Rows are tenant-scoped by the model, so these rules depend on role alone.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->isOwner();
    }
}
```

`backend/app/Http/Requests/Expense/StoreExpenseRequest.php`:

```php
<?php

namespace App\Http\Requests\Expense;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Expense::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'note' => ['nullable', 'string', 'max:255'],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where('organization_id', app(CurrentTenant::class)->id()),
            ],
        ];
    }
}
```

`backend/app/Http/Requests/Expense/UpdateExpenseRequest.php` — identical rules with `sometimes` in front of each, and `authorize()` returning `$this->user()->can('update', $this->route('expense'))`:

```php
<?php

namespace App\Http\Requests\Expense;

use App\Enums\ExpenseCategory;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('expense'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'required', Rule::enum(ExpenseCategory::class)],
            'expense_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999999.99'],
            'note' => ['nullable', 'string', 'max:255'],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where('organization_id', app(CurrentTenant::class)->id()),
            ],
        ];
    }
}
```

`backend/app/Http/Resources/ExpenseResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'expense_date' => $this->expense_date->toDateString(),
            'amount' => $this->amount,
            'note' => $this->note,
            'branch_id' => $this->branch_id,
            'payroll_run_id' => $this->payroll_run_id,
            'is_locked' => $this->isSystemGenerated(),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'created_at' => $this->created_at,
        ];
    }
}
```

`backend/app/Http/Controllers/ExpenseController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The salon's costs. Expenses are auto-scoped by BelongsToOrganization, so
 * route-model binding cannot reach another tenant's row (a foreign id 404s).
 *
 * Rows created by a finalized payroll run are read-only here: they change
 * only when their run does, which keeps payroll and the P&L in step.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Expense::class);

        // Default window is the current month — the log is a month view, and
        // an unbounded list grows without limit.
        $to = $request->date('to') ?? Carbon::now(config('app.timezone'))->startOfDay();
        $from = $request->date('from') ?? $to->copy()->startOfMonth();

        $expenses = Expense::query()
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->with('recorder')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        return ExpenseResource::collection($expenses);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = Expense::create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return (new ExpenseResource($expense))->response()->setStatusCode(201);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        if ($expense->isSystemGenerated()) {
            return response()->json([
                'message' => 'This expense comes from a payroll run. Change the run instead.',
            ], 422);
        }

        $expense->update($request->validated());

        return (new ExpenseResource($expense->fresh()))->response();
    }

    public function destroy(Expense $expense): Response|JsonResponse
    {
        $this->authorize('delete', $expense);

        if ($expense->isSystemGenerated()) {
            return response()->json([
                'message' => 'This expense comes from a payroll run. Delete the run instead.',
            ], 422);
        }

        $expense->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 5: Register the routes**

In `backend/routes/api.php`, inside the authenticated `tenant` group (next to the `reports` route), add:

```php
    Route::apiResource('expenses', ExpenseController::class)->except('show');
```

and `use App\Http\Controllers\ExpenseController;` at the top.

- [ ] **Step 6: Run the tests**

Run: `cd backend && php artisan test --filter=ExpenseTest`
Expected: PASS (7 tests).

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Enums/ExpenseCategory.php app/Models/Expense.php app/Policies/ExpensePolicy.php app/Http/Requests/Expense app/Http/Resources/ExpenseResource.php app/Http/Controllers/ExpenseController.php database/migrations/2026_08_08_100100_create_expenses_table.php routes/api.php tests/Feature/Finance/ExpenseTest.php
git commit -m "feat(finance): log salon expenses"
```

---

### Task 3: Payroll tables and the calculator

**Files:**
- Create: `backend/app/Enums/PayrollRunStatus.php`
- Create: `backend/database/migrations/2026_08_08_100200_create_payroll_runs_table.php`
- Create: `backend/database/migrations/2026_08_08_100300_create_payroll_lines_table.php`
- Create: `backend/database/migrations/2026_08_08_100400_add_payroll_run_foreign_key_to_expenses_table.php`
- Create: `backend/app/Models/PayrollRun.php`
- Create: `backend/app/Models/PayrollLine.php`
- Create: `backend/app/Services/PayrollCalculator.php`
- Create: `backend/tests/Feature/Finance/PayrollCalculatorTest.php`

**Interfaces:**
- Consumes: `PayType` (Task 1), `Expense` (Task 2), `FinanceTestCase` (Task 1).
- Produces: `App\Enums\PayrollRunStatus` (`DRAFT`, `FINALIZED`). `App\Models\PayrollRun` with `lines()`, `expense()`, `finalizer()`, `isDraft(): bool`. `App\Models\PayrollLine` with `run()`, `staff()`. `App\Services\PayrollCalculator::linesFor(CarbonInterface $month): array` returning rows keyed `staff_id, staff_name, pay_type, commission_rate, monthly_salary, earned_revenue, bookings, salary_amount, commission_amount, total_amount`.

This task is tested through the calculator directly (no HTTP), but it lives in `tests/Feature` because it hits the database and needs a bound tenant.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Finance/PayrollCalculatorTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Services\PayrollCalculator;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

class PayrollCalculatorTest extends FinanceTestCase
{
    /**
     * The calculator relies on the tenant global scope, which HTTP requests
     * get from ResolveTenant. Bind it by hand for these direct calls.
     */
    private function calculateFor(\App\Models\Organization $org, string $month): array
    {
        app(CurrentTenant::class)->set($org);

        return app(PayrollCalculator::class)->linesFor(Carbon::parse($month));
    }

    public function test_commission_staff_earn_a_percentage_of_completed_revenue(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 25]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-05', 'price' => 400]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-20', 'price' => 600]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertCount(1, $lines);
        $this->assertSame(1000.0, $lines[0]['earned_revenue']);
        $this->assertSame(2, $lines[0]['bookings']);
        $this->assertSame(250.0, $lines[0]['commission_amount']);
        $this->assertSame(0.0, $lines[0]['salary_amount']);
        $this->assertSame(250.0, $lines[0]['total_amount']);
    }

    public function test_salary_staff_are_paid_regardless_of_bookings(): void
    {
        $org = $this->makeOrg();
        $this->makeStaff($org, ['pay_type' => 'salary', 'monthly_salary' => 900]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertSame(0.0, $lines[0]['earned_revenue']);
        $this->assertSame(900.0, $lines[0]['salary_amount']);
        $this->assertSame(0.0, $lines[0]['commission_amount']);
        $this->assertSame(900.0, $lines[0]['total_amount']);
    }

    public function test_hybrid_staff_get_salary_plus_commission(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeStaff($org, ['pay_type' => 'hybrid', 'monthly_salary' => 1000, 'commission_rate' => 10]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-05', 'price' => 500]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertSame(1050.0, $lines[0]['total_amount']);
    }

    public function test_staff_with_no_pay_rule_are_excluded(): void
    {
        $org = $this->makeOrg();
        $this->makeStaff($org); // pay_type 'none'

        $this->assertSame([], $this->calculateFor($org, '2026-07-01'));
    }

    public function test_only_completed_appointments_inside_the_month_count(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 50]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-10', 'price' => 100]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-11', 'price' => 100, 'status' => 'cancelled']);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-12', 'price' => 100, 'status' => 'pending']);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-08-01', 'price' => 100]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-06-30', 'price' => 100]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertSame(100.0, $lines[0]['earned_revenue']);
        $this->assertSame(50.0, $lines[0]['commission_amount']);
    }

    public function test_commission_rounds_to_two_decimals(): void
    {
        $org = $this->makeOrg();
        $staff = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 33.33]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-10', 'price' => 1000.01]);

        $lines = $this->calculateFor($org, '2026-07-01');

        // 1000.01 * 0.3333 = 333.303333 -> 333.30
        $this->assertSame(333.3, $lines[0]['commission_amount']);
    }

    public function test_another_tenants_revenue_never_leaks_into_a_line(): void
    {
        $org = $this->makeOrg();
        $other = $this->makeOrg('other');
        $mine = $this->makeStaff($org, ['pay_type' => 'commission', 'commission_rate' => 100]);
        $theirs = $this->makeStaff($other, ['pay_type' => 'commission', 'commission_rate' => 100]);
        $this->makeAppointment($org, ['staff' => $mine, 'date' => '2026-07-10', 'price' => 100]);
        $this->makeAppointment($other, ['staff' => $theirs, 'date' => '2026-07-10', 'price' => 900]);

        $lines = $this->calculateFor($org, '2026-07-01');

        $this->assertCount(1, $lines);
        $this->assertSame(100.0, $lines[0]['earned_revenue']);
    }
}
```

`CurrentTenant` exposes `set(Organization)`, `id()`, and `check()` — `set()` is what binds the tenant here.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=PayrollCalculatorTest`
Expected: FAIL — `Class "App\Services\PayrollCalculator" not found`.

- [ ] **Step 3: Create the status enum and migrations**

`backend/app/Enums/PayrollRunStatus.php`:

```php
<?php

namespace App\Enums;

enum PayrollRunStatus: string
{
    case DRAFT = 'draft';
    case FINALIZED = 'finalized';
}
```

`backend/database/migrations/2026_08_08_100200_create_payroll_runs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One month of staff pay. `period_month` is always the 1st of the month;
     * the unique index with organization_id is what stops a salon paying the
     * same month twice.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('period_month');
            $table->string('status')->default('draft');
            $table->decimal('total_salary', 10, 2)->default(0);
            $table->decimal('total_commission', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
```

`backend/database/migrations/2026_08_08_100300_create_payroll_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one staff member was paid for one month. The pay rule is
     * snapshotted, so a later raise never rewrites a past month, and
     * `staff_name` is kept so deleting a staff account does not erase the
     * record of what they were paid.
     *
     * `earned_revenue` and `bookings` are computed and never edited: when an
     * owner overrides an amount, the reality it departs from stays visible.
     */
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('staff_name');
            $table->string('pay_type');
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->decimal('monthly_salary', 10, 2)->default(0);
            $table->decimal('earned_revenue', 10, 2)->default(0);
            $table->unsignedInteger('bookings')->default(0);
            $table->decimal('salary_amount', 10, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamps();
            $table->index('payroll_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
```

`backend/database/migrations/2026_08_08_100400_add_payroll_run_foreign_key_to_expenses_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a payroll run must take its salary expense with it, so the
     * P&L never shows pay for a run that no longer exists. The column was
     * created with `expenses`; the constraint waits until payroll_runs does.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['payroll_run_id']);
        });
    }
};
```

- [ ] **Step 4: Create the models**

`backend/app/Models/PayrollRun.php`:

```php
<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'period_month',
        'status',
        'total_salary',
        'total_commission',
        'total_amount',
        'finalized_at',
        'finalized_by',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'status' => PayrollRunStatus::class,
            'total_salary' => 'decimal:2',
            'total_commission' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'finalized_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === PayrollRunStatus::DRAFT;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /** The salary expense this run wrote when it was finalized. */
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
```

`backend/app/Models/PayrollLine.php`:

```php
<?php

namespace App\Models;

use App\Enums\PayType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No tenant scope of its own: a line is only ever reached through its run,
 * which is scoped, and it carries no organization_id.
 */
class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_run_id',
        'staff_id',
        'staff_name',
        'pay_type',
        'commission_rate',
        'monthly_salary',
        'earned_revenue',
        'bookings',
        'salary_amount',
        'commission_amount',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'pay_type' => PayType::class,
            'commission_rate' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'earned_revenue' => 'decimal:2',
            'salary_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'bookings' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
```

- [ ] **Step 5: Write the calculator**

`backend/app/Services/PayrollCalculator.php`:

```php
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
```

- [ ] **Step 6: Run the tests**

Run: `cd backend && php artisan test --filter=PayrollCalculatorTest`
Expected: PASS (7 tests).

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Enums/PayrollRunStatus.php app/Models/PayrollRun.php app/Models/PayrollLine.php app/Services/PayrollCalculator.php database/migrations/2026_08_08_1002*.php database/migrations/2026_08_08_1003*.php database/migrations/2026_08_08_1004*.php tests/Feature/Finance/PayrollCalculatorTest.php
git commit -m "feat(finance): compute a month of staff pay from each pay rule"
```

---

### Task 4: Payroll run API — create, list, show

**Files:**
- Create: `backend/app/Policies/PayrollRunPolicy.php`
- Create: `backend/app/Http/Requests/Payroll/StorePayrollRunRequest.php`
- Create: `backend/app/Http/Resources/PayrollLineResource.php`
- Create: `backend/app/Http/Resources/PayrollRunResource.php`
- Create: `backend/app/Http/Controllers/PayrollRunController.php`
- Create: `backend/tests/Feature/Finance/PayrollRunTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: `PayrollCalculator::linesFor()`, `PayrollRun`, `PayrollLine` (Task 3).
- Produces: `GET /api/payroll/runs`, `POST /api/payroll/runs`, `GET /api/payroll/runs/{run}`. `PayrollRunResource` emits `id, period_month` (`YYYY-MM-DD`), `period_label` (`August 2026`), `status`, `total_salary`, `total_commission`, `total_amount`, `finalized_at`, and `lines` when loaded. `PayrollRunController::syncTotals(PayrollRun $run): void` — used again in Task 5.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Finance/PayrollRunTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Models\PayrollRun;

class PayrollRunTest extends FinanceTestCase
{
    public function test_owner_can_open_a_run_for_a_month(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $staff = $this->makeStaff($org, ['pay_type' => 'hybrid', 'monthly_salary' => 1000, 'commission_rate' => 10]);
        $this->makeAppointment($org, ['staff' => $staff, 'date' => '2026-07-15', 'price' => 500]);

        $res = $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => '2026-07-01']);

        $res->assertCreated();
        $res->assertJsonPath('data.status', 'draft');
        $res->assertJsonPath('data.period_label', 'July 2026');
        $res->assertJsonPath('data.total_amount', '1050.00');
        $res->assertJsonCount(1, 'data.lines');
        $res->assertJsonPath('data.lines.0.staff_name', 'Sam Stylist');
        $res->assertJsonPath('data.lines.0.commission_amount', '50.00');
    }

    public function test_a_mid_month_date_is_normalised_to_the_first(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => '2026-07-19'])
            ->assertCreated()
            ->assertJsonPath('data.period_month', '2026-07-01');
    }

    public function test_a_second_run_for_the_same_month_is_rejected(): void
    {
        $org = $this->makeOrg();
        $token = $this->token($this->makeUser($org, 'owner'));

        $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-07-01'])->assertCreated();
        $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-07-15'])->assertStatus(422);

        $this->assertSame(1, PayrollRun::count());
    }

    public function test_a_future_month_is_rejected(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');

        $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => now()->addMonth()->startOfMonth()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period_month');
    }

    public function test_runs_are_listed_newest_month_first(): void
    {
        $org = $this->makeOrg();
        $token = $this->token($this->makeUser($org, 'owner'));
        $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-05-01']);
        $this->withToken($token)->postJson('/api/payroll/runs', ['period_month' => '2026-07-01']);

        $res = $this->withToken($token)->getJson('/api/payroll/runs');

        $res->assertOk();
        $res->assertJsonCount(2, 'data');
        $res->assertJsonPath('data.0.period_month', '2026-07-01');
    }

    public function test_manager_and_staff_cannot_reach_payroll(): void
    {
        $org = $this->makeOrg();

        foreach (['manager', 'staff'] as $role) {
            $token = $this->token($this->makeUser($org, $role));
            $this->withToken($token)->getJson('/api/payroll/runs')->assertForbidden();
            $this->withToken($token)
                ->postJson('/api/payroll/runs', ['period_month' => '2026-07-01'])
                ->assertForbidden();
        }
    }

    public function test_another_tenants_run_is_not_found(): void
    {
        $org = $this->makeOrg();
        $other = $this->makeOrg('other');
        $owner = $this->makeUser($org, 'owner');
        $theirs = PayrollRun::create(['organization_id' => $other->id, 'period_month' => '2026-07-01']);

        $this->withToken($this->token($owner))
            ->getJson("/api/payroll/runs/{$theirs->id}")
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=PayrollRunTest`
Expected: FAIL — 404 on `/api/payroll/runs`.

- [ ] **Step 3: Create the policy and request**

`backend/app/Policies/PayrollRunPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

/**
 * Payroll is every colleague's salary in one table — owner-only, reads
 * included. Runs are tenant-scoped by the model, so these rules depend on
 * the actor's role alone.
 */
class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, PayrollRun $run): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, PayrollRun $run): bool
    {
        return $user->isOwner();
    }
}
```

`backend/app/Http/Requests/Payroll/StorePayrollRunRequest.php`:

```php
<?php

namespace App\Http\Requests\Payroll;

use App\Models\PayrollRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StorePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PayrollRun::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The current month is allowed and produces a partial run; a month
        // that has not started has no revenue to pay anyone from.
        $limit = Carbon::now(config('app.timezone'))->endOfMonth()->toDateString();

        return [
            'period_month' => ['required', 'date', "before_or_equal:{$limit}"],
        ];
    }

    /** Any day in the month resolves to that month. */
    public function periodMonth(): Carbon
    {
        return Carbon::parse($this->date('period_month'))->startOfMonth();
    }
}
```

- [ ] **Step 4: Create the resources**

`backend/app/Http/Resources/PayrollLineResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'staff_name' => $this->staff_name,
            'pay_type' => $this->pay_type->value,
            'commission_rate' => $this->commission_rate,
            'monthly_salary' => $this->monthly_salary,
            'earned_revenue' => $this->earned_revenue,
            'bookings' => $this->bookings,
            'salary_amount' => $this->salary_amount,
            'commission_amount' => $this->commission_amount,
            'total_amount' => $this->total_amount,
        ];
    }
}
```

`backend/app/Http/Resources/PayrollRunResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_month' => $this->period_month->toDateString(),
            'period_label' => $this->period_month->format('F Y'),
            'status' => $this->status->value,
            'total_salary' => $this->total_salary,
            'total_commission' => $this->total_commission,
            'total_amount' => $this->total_amount,
            'finalized_at' => $this->finalized_at,
            'lines' => PayrollLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
```

- [ ] **Step 5: Create the controller**

`backend/app/Http/Controllers/PayrollRunController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payroll\StorePayrollRunRequest;
use App\Http\Resources\PayrollRunResource;
use App\Models\PayrollRun;
use App\Services\PayrollCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly staff pay. Runs are auto-scoped by BelongsToOrganization, so
 * route-model binding cannot reach another tenant's payroll (a foreign id
 * 404s). Every ability is owner-only, reads included.
 */
class PayrollRunController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::query()->orderByDesc('period_month')->get();

        return PayrollRunResource::collection($runs);
    }

    public function store(StorePayrollRunRequest $request, PayrollCalculator $calculator): JsonResponse
    {
        $month = $request->periodMonth();

        // Checked here for a readable message; the unique index is the backstop.
        if (PayrollRun::query()->whereDate('period_month', $month->toDateString())->exists()) {
            return response()->json([
                'message' => 'Payroll for '.$month->format('F Y').' already exists.',
            ], 422);
        }

        $run = DB::transaction(function () use ($month, $calculator) {
            $run = PayrollRun::create(['period_month' => $month->toDateString()]);

            foreach ($calculator->linesFor($month) as $line) {
                $run->lines()->create($line);
            }

            $this->syncTotals($run);

            return $run;
        });

        return (new PayrollRunResource($run->fresh()->load('lines')))
            ->response()->setStatusCode(201);
    }

    public function show(PayrollRun $run): JsonResponse
    {
        $this->authorize('view', $run);

        return (new PayrollRunResource($run->load('lines')))->response();
    }

    /**
     * Recompute the run's totals from its lines. Called on create and again
     * after every line edit, so the header always matches the rows under it.
     */
    public function syncTotals(PayrollRun $run): void
    {
        $lines = $run->lines()->get();

        $run->update([
            'total_salary' => round((float) $lines->sum('salary_amount'), 2),
            'total_commission' => round((float) $lines->sum('commission_amount'), 2),
            'total_amount' => round((float) $lines->sum('total_amount'), 2),
        ]);
    }
}
```

- [ ] **Step 6: Register the routes**

In `backend/routes/api.php`, inside the authenticated `tenant` group, next to `expenses`:

```php
    Route::get('payroll/runs', [PayrollRunController::class, 'index']);
    Route::post('payroll/runs', [PayrollRunController::class, 'store']);
    Route::get('payroll/runs/{run}', [PayrollRunController::class, 'show']);
```

and `use App\Http\Controllers\PayrollRunController;` at the top.

- [ ] **Step 7: Run the tests**

Run: `cd backend && php artisan test --filter=PayrollRunTest`
Expected: PASS (7 tests).

- [ ] **Step 8: Commit**

```bash
cd backend && git add app/Policies/PayrollRunPolicy.php app/Http/Requests/Payroll app/Http/Resources/PayrollRunResource.php app/Http/Resources/PayrollLineResource.php app/Http/Controllers/PayrollRunController.php routes/api.php tests/Feature/Finance/PayrollRunTest.php
git commit -m "feat(finance): open a monthly payroll run"
```

---

### Task 5: Edit lines, finalize, delete

**Files:**
- Create: `backend/app/Http/Requests/Payroll/UpdatePayrollLineRequest.php`
- Create: `backend/app/Http/Controllers/PayrollLineController.php`
- Create: `backend/tests/Feature/Finance/PayrollFinalizeTest.php`
- Modify: `backend/app/Http/Controllers/PayrollRunController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: `PayrollRunController::syncTotals()` (Task 4), `Expense` + `ExpenseCategory` (Task 2).
- Produces: `PATCH /api/payroll/runs/{run}/lines/{line}`, `POST /api/payroll/runs/{run}/finalize`, `DELETE /api/payroll/runs/{run}`.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Finance/PayrollFinalizeTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;
use App\Models\Organization;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;

class PayrollFinalizeTest extends FinanceTestCase
{
    /** @return array{0: User, 1: PayrollRun} */
    private function draftRun(Organization $org, string $month = '2026-07-01'): array
    {
        $owner = $this->makeUser($org, 'owner');
        $this->makeStaff($org, ['pay_type' => 'salary', 'monthly_salary' => 1000]);

        $res = $this->withToken($this->token($owner))
            ->postJson('/api/payroll/runs', ['period_month' => $month]);

        return [$owner, PayrollRun::findOrFail($res->json('data.id'))];
    }

    public function test_owner_can_edit_a_line_on_a_draft(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $line = $run->lines()->first();

        $res = $this->withToken($this->token($owner))
            ->patchJson("/api/payroll/runs/{$run->id}/lines/{$line->id}", ['salary_amount' => 600]);

        $res->assertOk();
        $res->assertJsonPath('data.total_amount', '600.00');
        $this->assertSame('600.00', $run->fresh()->total_amount);
        // The computed revenue is untouched, so the override stays visible.
        $this->assertSame('0.00', $line->fresh()->earned_revenue);
    }

    public function test_finalize_locks_the_run_and_writes_one_salary_expense(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);

        $res = $this->withToken($this->token($owner))
            ->postJson("/api/payroll/runs/{$run->id}/finalize");

        $res->assertOk();
        $res->assertJsonPath('data.status', 'finalized');

        $this->assertSame(1, Expense::count());
        $expense = Expense::first();
        $this->assertSame('salary', $expense->category->value);
        $this->assertSame('1000.00', $expense->amount);
        $this->assertSame('2026-07-31', $expense->expense_date->toDateString());
        $this->assertSame($run->id, $expense->payroll_run_id);
        $this->assertSame($owner->id, $run->fresh()->finalized_by);
    }

    public function test_a_finalized_run_cannot_be_edited_or_finalized_again(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $line = $run->lines()->first();
        $token = $this->token($owner);

        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertOk();

        $this->withToken($token)
            ->patchJson("/api/payroll/runs/{$run->id}/lines/{$line->id}", ['salary_amount' => 1])
            ->assertStatus(422);
        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertStatus(422);

        $this->assertSame(1, Expense::count());
    }

    public function test_deleting_a_finalized_run_removes_its_lines_and_salary_expense(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $token = $this->token($owner);
        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertOk();

        $this->withToken($token)->deleteJson("/api/payroll/runs/{$run->id}")->assertNoContent();

        $this->assertSame(0, PayrollRun::count());
        $this->assertSame(0, PayrollLine::count());
        $this->assertSame(0, Expense::count());
    }

    public function test_a_payroll_expense_cannot_be_edited_or_deleted_directly(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org);
        $token = $this->token($owner);
        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertOk();
        $expense = Expense::first();

        $this->withToken($token)->patchJson("/api/expenses/{$expense->id}", ['amount' => 5])->assertStatus(422);
        $this->withToken($token)->deleteJson("/api/expenses/{$expense->id}")->assertStatus(422);
        $this->assertSame(1, Expense::count());
    }

    public function test_a_line_from_a_different_run_is_not_found(): void
    {
        $org = $this->makeOrg();
        [$owner, $run] = $this->draftRun($org, '2026-07-01');
        $token = $this->token($owner);
        $otherId = $this->withToken($token)
            ->postJson('/api/payroll/runs', ['period_month' => '2026-06-01'])
            ->json('data.id');
        $foreignLine = PayrollRun::findOrFail($otherId)->lines()->first();

        $this->withToken($token)
            ->patchJson("/api/payroll/runs/{$run->id}/lines/{$foreignLine->id}", ['salary_amount' => 1])
            ->assertNotFound();
    }

    public function test_manager_cannot_finalize_or_delete(): void
    {
        $org = $this->makeOrg();
        [, $run] = $this->draftRun($org);
        $token = $this->token($this->makeUser($org, 'manager'));

        $this->withToken($token)->postJson("/api/payroll/runs/{$run->id}/finalize")->assertForbidden();
        $this->withToken($token)->deleteJson("/api/payroll/runs/{$run->id}")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=PayrollFinalizeTest`
Expected: FAIL — 405/404 on the finalize and line routes.

- [ ] **Step 3: Create the line request and controller**

`backend/app/Http/Requests/Payroll/UpdatePayrollLineRequest.php`:

```php
<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayrollLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('run'));
    }

    /**
     * Only the two payable amounts are editable. earned_revenue and bookings
     * are computed facts, not opinions.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'salary_amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
            'commission_amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
```

`backend/app/Http/Controllers/PayrollLineController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payroll\UpdatePayrollLineRequest;
use App\Http\Resources\PayrollLineResource;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;

/**
 * One staff member's row inside a payroll run. The run is tenant-scoped by
 * its model; the line is checked against that run so a valid id under the
 * wrong run 404s rather than editing the wrong month.
 */
class PayrollLineController extends Controller
{
    public function update(
        UpdatePayrollLineRequest $request,
        PayrollRun $run,
        PayrollLine $line,
        PayrollRunController $runs,
    ): JsonResponse {
        abort_unless($line->payroll_run_id === $run->id, 404);

        if (! $run->isDraft()) {
            return response()->json([
                'message' => 'This payroll run is finalized. Delete it and create it again to change it.',
            ], 422);
        }

        $data = $request->validated();
        $line->fill($data);
        $line->total_amount = round((float) $line->salary_amount + (float) $line->commission_amount, 2);
        $line->save();

        $runs->syncTotals($run);

        return (new PayrollLineResource($line->fresh()))->response();
    }
}
```

- [ ] **Step 4: Add finalize and destroy to PayrollRunController**

Append these methods to `backend/app/Http/Controllers/PayrollRunController.php`:

```php
    /**
     * Lock the run and book it as a cost. The salary expense is what makes
     * staff pay show up in the P&L, and it is written here — once — so the
     * two can never drift apart.
     */
    public function finalize(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authorize('update', $run);

        if (! $run->isDraft()) {
            return response()->json(['message' => 'This payroll run is already finalized.'], 422);
        }

        DB::transaction(function () use ($request, $run) {
            $this->syncTotals($run);
            $run->refresh();

            $run->update([
                'status' => PayrollRunStatus::FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $request->user()->id,
            ]);

            $run->expense()->create([
                'organization_id' => $run->organization_id,
                'category' => ExpenseCategory::SALARY,
                'expense_date' => $run->period_month->copy()->endOfMonth()->toDateString(),
                'amount' => $run->total_amount,
                'note' => 'Payroll — '.$run->period_month->format('F Y'),
                'recorded_by' => $request->user()->id,
            ]);
        });

        return (new PayrollRunResource($run->fresh()->load('lines')))->response();
    }

    /**
     * Correcting a finalized month means deleting it and running it again.
     * Lines and the salary expense go with it (both cascade).
     */
    public function destroy(PayrollRun $run): Response
    {
        $this->authorize('delete', $run);

        $run->delete();

        return response()->noContent();
    }
```

Add the imports: `use App\Enums\ExpenseCategory;`, `use App\Enums\PayrollRunStatus;`, `use Illuminate\Http\Request;`, `use Illuminate\Http\Response;`.

- [ ] **Step 5: Register the routes**

In `backend/routes/api.php`, beside the payroll routes from Task 4:

```php
    Route::delete('payroll/runs/{run}', [PayrollRunController::class, 'destroy']);
    Route::post('payroll/runs/{run}/finalize', [PayrollRunController::class, 'finalize']);
    Route::patch('payroll/runs/{run}/lines/{line}', [PayrollLineController::class, 'update']);
```

and `use App\Http\Controllers\PayrollLineController;`.

- [ ] **Step 6: Run the tests**

Run: `cd backend && php artisan test --filter=PayrollFinalizeTest`
Expected: PASS (7 tests).

If SQLite reports the cascade did not delete the expense, confirm foreign key enforcement is on in the test connection (`DatabaseDriverTest` documents the project's setup); if it is off, delete the expense explicitly in `destroy()` before deleting the run, inside a transaction.

- [ ] **Step 7: Run the full suite**

Run: `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
cd backend && git add app/Http/Requests/Payroll/UpdatePayrollLineRequest.php app/Http/Controllers/PayrollLineController.php app/Http/Controllers/PayrollRunController.php routes/api.php tests/Feature/Finance/PayrollFinalizeTest.php
git commit -m "feat(finance): finalize a payroll run into a salary expense"
```

---

### Task 6: Profit in reports

**Files:**
- Modify: `backend/app/Services/ReportService.php`
- Modify: `backend/app/Http/Controllers/ReportController.php`
- Create: `backend/tests/Feature/Finance/ProfitReportTest.php`

**Interfaces:**
- Consumes: `Expense` (Task 2).
- Produces: `data.profit` on `GET /api/reports` — `{earned, expenses_total, expenses_by_category: [{category, amount, share_pct}], net_profit}`. Present for owners, absent for managers.

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Finance/ProfitReportTest.php`:

```php
<?php

namespace Tests\Feature\Finance;

use App\Models\Expense;

class ProfitReportTest extends FinanceTestCase
{
    public function test_profit_is_earned_revenue_minus_expenses_in_range(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        $this->makeAppointment($org, ['date' => '2026-07-10', 'price' => 500]);
        Expense::create([
            'organization_id' => $org->id, 'category' => 'rent',
            'expense_date' => '2026-07-01', 'amount' => 200,
        ]);
        Expense::create([
            'organization_id' => $org->id, 'category' => 'supplies',
            'expense_date' => '2026-07-05', 'amount' => 50,
        ]);

        $res = $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-01&to=2026-07-31');

        $res->assertOk();
        $res->assertJsonPath('data.profit.earned', 500);
        $res->assertJsonPath('data.profit.expenses_total', 250);
        $res->assertJsonPath('data.profit.net_profit', 250);
        $res->assertJsonPath('data.profit.expenses_by_category.0.category', 'rent');
        $res->assertJsonPath('data.profit.expenses_by_category.0.amount', 200);
    }

    public function test_expenses_outside_the_range_are_excluded(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        Expense::create([
            'organization_id' => $org->id, 'category' => 'rent',
            'expense_date' => '2026-06-30', 'amount' => 999,
        ]);

        $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-01&to=2026-07-31')
            ->assertJsonPath('data.profit.expenses_total', 0);
    }

    public function test_net_profit_can_be_negative(): void
    {
        $org = $this->makeOrg();
        $owner = $this->makeUser($org, 'owner');
        Expense::create([
            'organization_id' => $org->id, 'category' => 'equipment',
            'expense_date' => '2026-07-02', 'amount' => 300,
        ]);

        $this->withToken($this->token($owner))
            ->getJson('/api/reports?from=2026-07-01&to=2026-07-31')
            ->assertJsonPath('data.profit.net_profit', -300);
    }

    public function test_manager_gets_the_report_without_the_profit_block(): void
    {
        $org = $this->makeOrg();
        $manager = $this->makeUser($org, 'manager');

        $res = $this->withToken($this->token($manager))->getJson('/api/reports');

        $res->assertOk();
        $res->assertJsonMissingPath('data.profit');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php artisan test --filter=ProfitReportTest`
Expected: FAIL — `data.profit` missing.

- [ ] **Step 3: Add the profit block to ReportService**

In `backend/app/Services/ReportService.php`, add `'profit' => $this->profit($from, $to),` to the array returned by `build()` (after `'bookings'`), and add this method:

```php
    /**
     * Earned revenue against everything the salon spent in the same window.
     * Staff pay arrives here as the single salary expense a finalized payroll
     * run writes, so it is counted exactly once.
     *
     * @return array<string, mixed>
     */
    protected function profit(string $from, string $to): array
    {
        $rows = Expense::query()
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->selectRaw('category, SUM(amount) as amount')
            ->groupBy('category')
            ->get();

        $total = round((float) $rows->sum('amount'), 2);
        $earned = $this->earnedWindow($from, $to)['earned'];

        return [
            'earned' => $earned,
            'expenses_total' => $total,
            'expenses_by_category' => $rows
                ->sortByDesc(fn ($row) => (float) $row->amount)
                ->map(fn ($row) => [
                    'category' => $row->category,
                    'amount' => round((float) $row->amount, 2),
                    'share_pct' => $total > 0 ? round((float) $row->amount / $total * 100, 1) : 0.0,
                ])
                ->values()
                ->all(),
            'net_profit' => round($earned - $total, 2),
        ];
    }
```

Add `use App\Models\Expense;` to the imports.

- [ ] **Step 4: Hide the block from managers**

Replace the body of `backend/app/Http/Controllers/ReportController.php`'s `__invoke`:

```php
    public function __invoke(ReportRequest $request, ReportService $reports): JsonResponse
    {
        ['from' => $from, 'to' => $to] = $request->range();

        $data = $reports->build($from, $to);

        // Costs and profit are owner-only. Managers keep the rest of the
        // report rather than losing a page they legitimately use.
        if (! $request->user()->isOwner()) {
            unset($data['profit']);
        }

        return response()->json(['data' => $data]);
    }
```

- [ ] **Step 5: Run the tests**

Run: `cd backend && php artisan test --filter=ProfitReportTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suite**

Run: `cd backend && php artisan test`
Expected: PASS — `ReportsTest` still green.

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Services/ReportService.php app/Http/Controllers/ReportController.php tests/Feature/Finance/ProfitReportTest.php
git commit -m "feat(finance): show net profit on the reports endpoint"
```

---

### Task 7: Compensation section on the staff form

**Files:**
- Create: `frontend/src/lib/payroll.js`
- Create: `frontend/src/lib/payroll.spec.js`
- Modify: `frontend/src/views/StaffView.vue`

**Interfaces:**
- Consumes: `pay_type` / `monthly_salary` / `commission_rate` on the staff API (Task 1).
- Produces: `frontend/src/lib/payroll.js` exporting `PAY_TYPES` (array of `{value, label, hint}`), `showsSalary(payType): boolean`, `showsRate(payType): boolean`, `monthOptions(count, today): Array<{value, label}>`, `payTypeLabel(payType): string`.

- [ ] **Step 1: Write the failing helper tests**

Create `frontend/src/lib/payroll.spec.js`:

```js
import { describe, it, expect } from 'vitest'
import { PAY_TYPES, showsSalary, showsRate, monthOptions, payTypeLabel } from './payroll'

describe('pay type fields', () => {
  it('offers the four rules the API accepts', () => {
    expect(PAY_TYPES.map((t) => t.value)).toEqual(['none', 'commission', 'salary', 'hybrid'])
  })

  it('shows a salary field for salary and hybrid only', () => {
    expect(showsSalary('salary')).toBe(true)
    expect(showsSalary('hybrid')).toBe(true)
    expect(showsSalary('commission')).toBe(false)
    expect(showsSalary('none')).toBe(false)
  })

  it('shows a rate field for commission and hybrid only', () => {
    expect(showsRate('commission')).toBe(true)
    expect(showsRate('hybrid')).toBe(true)
    expect(showsRate('salary')).toBe(false)
    expect(showsRate('none')).toBe(false)
  })

  it('labels an unknown pay type without throwing', () => {
    expect(payTypeLabel('salary')).toBe('Fixed salary')
    expect(payTypeLabel('nonsense')).toBe('—')
  })
})

describe('monthOptions', () => {
  it('lists the current month first, then earlier months', () => {
    const options = monthOptions(3, new Date(2026, 7, 8)) // Aug 8 2026

    expect(options).toEqual([
      { value: '2026-08-01', label: 'August 2026' },
      { value: '2026-07-01', label: 'July 2026' },
      { value: '2026-06-01', label: 'June 2026' },
    ])
  })

  it('crosses a year boundary', () => {
    const options = monthOptions(2, new Date(2026, 0, 15)) // Jan 15 2026

    expect(options[1]).toEqual({ value: '2025-12-01', label: 'December 2025' })
  })
})
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd frontend && npm run test:unit -- payroll`
Expected: FAIL — cannot resolve `./payroll`.

- [ ] **Step 3: Write the helper module**

Create `frontend/src/lib/payroll.js`:

```js
// Pay rules, mirrored from the backend PayType enum. Kept here rather than
// inline in a view because two screens need them: the staff form writes a
// rule, the payroll table reads one back.
export const PAY_TYPES = [
  { value: 'none', label: 'Not paid through SalonHub', hint: 'Skipped by payroll runs.' },
  { value: 'commission', label: 'Commission only', hint: 'A percentage of what they bill.' },
  { value: 'salary', label: 'Fixed salary', hint: 'The same amount every month.' },
  { value: 'hybrid', label: 'Salary + commission', hint: 'A monthly amount plus a percentage.' },
]

export function showsSalary(payType) {
  return payType === 'salary' || payType === 'hybrid'
}

export function showsRate(payType) {
  return payType === 'commission' || payType === 'hybrid'
}

export function payTypeLabel(payType) {
  return PAY_TYPES.find((type) => type.value === payType)?.label || '—'
}

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

/**
 * The last `count` months, newest first, as {value: 'YYYY-MM-01', label}.
 * `today` is injectable so the test does not depend on the wall clock.
 */
export function monthOptions(count, today = new Date()) {
  const options = []
  for (let i = 0; i < count; i += 1) {
    const date = new Date(today.getFullYear(), today.getMonth() - i, 1)
    const month = String(date.getMonth() + 1).padStart(2, '0')
    options.push({
      value: `${date.getFullYear()}-${month}-01`,
      label: `${MONTH_NAMES[date.getMonth()]} ${date.getFullYear()}`,
    })
  }
  return options
}
```

- [ ] **Step 4: Run the tests**

Run: `cd frontend && npm run test:unit -- payroll`
Expected: PASS (6 tests).

- [ ] **Step 5: Add the compensation section to the staff form**

In `frontend/src/views/StaffView.vue`:

1. Import the helpers and the auth store in `<script setup>`:

```js
import { PAY_TYPES, showsSalary, showsRate } from '@/lib/payroll'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const isOwner = computed(() => authStore.role === 'owner')
```

(`computed` is already imported; add `useAuthStore` only if the file does not already import it.)

2. Add the three fields to the `form` reactive object, next to `designation`:

```js
  pay_type: 'none',
  monthly_salary: '',
  commission_rate: '',
```

3. In the function that resets the form for a new staff member, set them back to `'none'`, `''`, `''`. In the function that fills the form from an existing member (the block with `designation: member.designation || ''`), add:

```js
    pay_type: member.pay_type || 'none',
    monthly_salary: member.monthly_salary ?? '',
    commission_rate: member.commission_rate ?? '',
```

4. In the payload builder (the block with `designation: form.designation || null`), add — owners only, so a manager's save never carries pay fields:

```js
  if (isOwner.value) {
    payload.pay_type = form.pay_type
    payload.monthly_salary = showsSalary(form.pay_type) ? Number(form.monthly_salary || 0) : 0
    payload.commission_rate = showsRate(form.pay_type) ? Number(form.commission_rate || 0) : 0
  }
```

5. In the form template, after the designation field, add the section:

```vue
        <div v-if="isOwner" class="border-t border-slate-200 pt-4">
          <h3 class="text-sm font-semibold text-slate-900">Compensation</h3>
          <p class="mt-1 text-xs text-slate-500">Used to work out this person's pay in a monthly payroll run.</p>

          <div class="mt-3 space-y-2">
            <label
              v-for="type in PAY_TYPES"
              :key="type.value"
              class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 px-3 py-2.5"
              :class="form.pay_type === type.value ? 'border-indigo-500 bg-indigo-50' : ''"
            >
              <input v-model="form.pay_type" type="radio" :value="type.value" class="mt-1" />
              <span>
                <span class="block text-sm font-medium text-slate-900">{{ type.label }}</span>
                <span class="block text-xs text-slate-500">{{ type.hint }}</span>
              </span>
            </label>
          </div>

          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div v-if="showsSalary(form.pay_type)">
              <label class="mb-1 block text-sm font-medium text-slate-700">Monthly salary</label>
              <input
                v-model="form.monthly_salary"
                type="number"
                min="0"
                step="0.01"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
              <p v-if="formErrors.monthly_salary" class="mt-1 text-sm text-rose-600">{{ formErrors.monthly_salary[0] }}</p>
            </div>
            <div v-if="showsRate(form.pay_type)">
              <label class="mb-1 block text-sm font-medium text-slate-700">Commission rate (%)</label>
              <input
                v-model="form.commission_rate"
                type="number"
                min="0"
                max="100"
                step="0.01"
                class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
              <p v-if="formErrors.commission_rate" class="mt-1 text-sm text-rose-600">{{ formErrors.commission_rate[0] }}</p>
            </div>
          </div>
        </div>
```

- [ ] **Step 6: Verify in the browser**

Run the app (`cd backend && php artisan serve` and `cd frontend && npm run dev`). As an owner, open Staff → edit a member → set "Salary + commission", 1000 and 25, save, reopen: the values come back. Log in as a manager: the Compensation section is not rendered.

- [ ] **Step 7: Commit**

```bash
cd frontend && git add src/lib/payroll.js src/lib/payroll.spec.js src/views/StaffView.vue
git commit -m "feat(finance): set a staff member's pay rule from the staff form"
```

---

### Task 8: Finance screen — payroll tab

**Files:**
- Create: `frontend/src/views/FinanceView.vue`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/layouts/DashboardLayout.vue`

**Interfaces:**
- Consumes: payroll API (Tasks 4-5), `@/lib/payroll` helpers (Task 7), `@/lib/api`, `parseApiError` from `@/lib/errors`.
- Produces: route `/finance` (name `finance`, `meta: { requiresAuth: true, roles: ['owner'] }`) rendering `FinanceView.vue`, which owns the tab state `payroll | expenses | profit`. Tasks 9 and 10 fill the other two tabs.

- [ ] **Step 1: Create the view with the payroll tab**

Create `frontend/src/views/FinanceView.vue`:

```vue
<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { parseApiError } from '@/lib/errors'
import { monthOptions, payTypeLabel } from '@/lib/payroll'

const authStore = useAuthStore()
const currency = computed(() => authStore.organization?.currency || 'USD')

const TABS = [
  { key: 'payroll', label: 'Payroll' },
  { key: 'expenses', label: 'Expenses' },
  { key: 'profit', label: 'Profit' },
]
const tab = ref('payroll')

const runs = ref([])
const activeRun = ref(null)
const loading = ref(false)
const error = ref('')
const months = monthOptions(12)
const selectedMonth = ref(months[0].value)

function money(value) {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.value })
    .format(Number(value || 0))
}

async function loadRuns() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/payroll/runs')
    runs.value = data.data
    if (runs.value.length && !activeRun.value) await openRun(runs.value[0].id)
  } catch (e) {
    error.value = parseApiError(e, 'Could not load payroll.').message
  } finally {
    loading.value = false
  }
}

async function openRun(id) {
  error.value = ''
  try {
    const { data } = await api.get(`/payroll/runs/${id}`)
    activeRun.value = data.data
  } catch (e) {
    error.value = parseApiError(e, 'Could not load this payroll run.').message
  }
}

async function createRun() {
  error.value = ''
  try {
    const { data } = await api.post('/payroll/runs', { period_month: selectedMonth.value })
    activeRun.value = data.data
    await loadRuns()
  } catch (e) {
    error.value = parseApiError(e, 'Could not open payroll.').message
  }
}

// Saves one edited amount and refreshes the run so the header total matches.
async function saveLine(line, field, value) {
  error.value = ''
  try {
    await api.patch(`/payroll/runs/${activeRun.value.id}/lines/${line.id}`, { [field]: Number(value || 0) })
    await openRun(activeRun.value.id)
    await loadRuns()
  } catch (e) {
    error.value = parseApiError(e, 'Could not save that amount.').message
  }
}

async function finalizeRun() {
  if (!window.confirm(`Finalize ${activeRun.value.period_label} for ${money(activeRun.value.total_amount)}? This locks the run and books it as an expense.`)) return
  error.value = ''
  try {
    const { data } = await api.post(`/payroll/runs/${activeRun.value.id}/finalize`)
    activeRun.value = data.data
    await loadRuns()
  } catch (e) {
    error.value = parseApiError(e, 'Could not finalize this run.').message
  }
}

async function deleteRun() {
  if (!window.confirm(`Delete payroll for ${activeRun.value.period_label}? Its salary expense goes with it.`)) return
  error.value = ''
  try {
    await api.delete(`/payroll/runs/${activeRun.value.id}`)
    activeRun.value = null
    await loadRuns()
  } catch (e) {
    error.value = parseApiError(e, 'Could not delete this run.').message
  }
}

onMounted(loadRuns)
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Finance</h1>
      <p class="mt-1 text-sm text-slate-500">Staff pay, costs, and what the salon actually keeps.</p>
    </div>

    <div class="flex gap-1 border-b border-slate-200">
      <button
        v-for="item in TABS"
        :key="item.key"
        class="border-b-2 px-4 py-2 text-sm font-medium transition"
        :class="tab === item.key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
        @click="tab = item.key"
      >
        {{ item.label }}
      </button>
    </div>

    <p v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ error }}</p>

    <section v-if="tab === 'payroll'" class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Month</label>
          <select v-model="selectedMonth" class="rounded-lg border border-slate-300 px-3 py-2.5 text-slate-900 shadow-sm">
            <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
        <button class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700" @click="createRun">
          Open payroll
        </button>
      </div>

      <div v-if="runs.length" class="flex flex-wrap gap-2">
        <button
          v-for="run in runs"
          :key="run.id"
          class="rounded-full border px-3 py-1 text-sm"
          :class="activeRun?.id === run.id ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-300 text-slate-600'"
          @click="openRun(run.id)"
        >
          {{ run.period_label }}
          <span v-if="run.status === 'finalized'" class="ml-1 text-xs text-emerald-600">✓</span>
        </button>
      </div>

      <p v-if="loading" class="text-sm text-slate-500">Loading…</p>
      <p v-else-if="!runs.length" class="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">
        No payroll yet. Pick a month and open it.
      </p>

      <div v-if="activeRun" class="overflow-hidden rounded-xl border border-slate-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
          <div>
            <p class="text-sm font-semibold text-slate-900">{{ activeRun.period_label }}</p>
            <p class="text-xs text-slate-500">
              <span v-if="activeRun.status === 'finalized'">Finalized {{ new Date(activeRun.finalized_at).toLocaleDateString() }}</span>
              <span v-else>Draft — amounts can still be edited</span>
            </p>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-sm font-semibold text-slate-900">{{ money(activeRun.total_amount) }}</span>
            <button
              v-if="activeRun.status === 'draft'"
              class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
              @click="finalizeRun"
            >
              Finalize
            </button>
            <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50" @click="deleteRun">
              Delete
            </button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-white text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th class="px-4 py-2">Staff</th>
                <th class="px-4 py-2">Rule</th>
                <th class="px-4 py-2 text-right">Bookings</th>
                <th class="px-4 py-2 text-right">Earned</th>
                <th class="px-4 py-2 text-right">Salary</th>
                <th class="px-4 py-2 text-right">Commission</th>
                <th class="px-4 py-2 text-right">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="line in activeRun.lines" :key="line.id">
                <td class="px-4 py-2 font-medium text-slate-900">{{ line.staff_name }}</td>
                <td class="px-4 py-2 text-slate-500">
                  {{ payTypeLabel(line.pay_type) }}
                  <span v-if="Number(line.commission_rate) > 0" class="text-xs">({{ line.commission_rate }}%)</span>
                </td>
                <td class="px-4 py-2 text-right">{{ line.bookings }}</td>
                <td class="px-4 py-2 text-right">{{ money(line.earned_revenue) }}</td>
                <td class="px-4 py-2 text-right">
                  <input
                    v-if="activeRun.status === 'draft'"
                    :value="line.salary_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="w-24 rounded border border-slate-300 px-2 py-1 text-right"
                    @change="saveLine(line, 'salary_amount', $event.target.value)"
                  />
                  <span v-else>{{ money(line.salary_amount) }}</span>
                </td>
                <td class="px-4 py-2 text-right">
                  <input
                    v-if="activeRun.status === 'draft'"
                    :value="line.commission_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    class="w-24 rounded border border-slate-300 px-2 py-1 text-right"
                    @change="saveLine(line, 'commission_amount', $event.target.value)"
                  />
                  <span v-else>{{ money(line.commission_amount) }}</span>
                </td>
                <td class="px-4 py-2 text-right font-semibold text-slate-900">{{ money(line.total_amount) }}</td>
              </tr>
              <tr v-if="!activeRun.lines.length">
                <td colspan="7" class="px-4 py-6 text-center text-slate-500">
                  No staff have a pay rule yet. Set one on the Staff page.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</template>
```

- [ ] **Step 2: Register the route**

In `frontend/src/router/index.js`, inside the `DashboardLayout` children, after the `reports` entry:

```js
        {
          path: 'finance',
          name: 'finance',
          component: () => import('@/views/FinanceView.vue'),
          meta: { requiresAuth: true, roles: ['owner'] },
        },
```

- [ ] **Step 3: Add the sidebar entry**

In `frontend/src/layouts/DashboardLayout.vue`, add to the `nav` array after the `Reports` entry:

```js
  {
    name: 'Finance',
    to: '/finance',
    roles: ['owner'],
    d: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  },
```

- [ ] **Step 4: Verify in the browser**

As an owner with at least one staff member on a commission rule and a completed appointment this month: open Finance → pick the current month → Open payroll. The line shows bookings, earned, and computed commission. Edit a salary cell — the header total updates. Finalize — the table locks and the badge appears. Log in as a manager: no Finance entry in the sidebar, and `/finance` redirects rather than rendering.

- [ ] **Step 5: Run the frontend tests**

Run: `cd frontend && npm run test:unit`
Expected: PASS (no regressions).

- [ ] **Step 6: Commit**

```bash
cd frontend && git add src/views/FinanceView.vue src/router/index.js src/layouts/DashboardLayout.vue
git commit -m "feat(finance): run monthly payroll from the dashboard"
```

---

### Task 9: Finance screen — expenses tab

**Files:**
- Modify: `frontend/src/views/FinanceView.vue`

**Interfaces:**
- Consumes: expenses API (Task 2), `Modal.vue` at `@/components/Modal.vue`.
- Produces: the `expenses` tab inside `FinanceView.vue`.

- [ ] **Step 1: Add the expense state and actions**

In `FinanceView.vue`'s `<script setup>`, extend the existing `vue` import with `reactive` (do not add a second `import … from 'vue'` line), then add:

```js
import Modal from '@/components/Modal.vue'

const EXPENSE_CATEGORIES = [
  'rent', 'utilities', 'supplies', 'salary', 'marketing', 'equipment', 'maintenance', 'other',
]

const expenses = ref([])
const expenseFilters = reactive({ from: '', to: '', category: '' })
const expenseModalOpen = ref(false)
const editingExpenseId = ref(null)
const expenseForm = reactive({ category: 'supplies', expense_date: '', amount: '', note: '' })
const expenseErrors = ref({})

function startOfMonth() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function today() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

async function loadExpenses() {
  error.value = ''
  try {
    const { data } = await api.get('/expenses', {
      params: {
        from: expenseFilters.from || startOfMonth(),
        to: expenseFilters.to || today(),
        category: expenseFilters.category || undefined,
      },
    })
    expenses.value = data.data
  } catch (e) {
    error.value = parseApiError(e, 'Could not load expenses.').message
  }
}

function openExpenseModal(expense = null) {
  expenseErrors.value = {}
  editingExpenseId.value = expense?.id ?? null
  Object.assign(expenseForm, {
    category: expense?.category ?? 'supplies',
    expense_date: expense?.expense_date ?? today(),
    amount: expense?.amount ?? '',
    note: expense?.note ?? '',
  })
  expenseModalOpen.value = true
}

async function saveExpense() {
  expenseErrors.value = {}
  const payload = {
    category: expenseForm.category,
    expense_date: expenseForm.expense_date,
    amount: Number(expenseForm.amount || 0),
    note: expenseForm.note || null,
  }
  try {
    if (editingExpenseId.value) {
      await api.patch(`/expenses/${editingExpenseId.value}`, payload)
    } else {
      await api.post('/expenses', payload)
    }
    expenseModalOpen.value = false
    await loadExpenses()
  } catch (e) {
    // A 422 carries per-field errors; anything else (including the "this came
    // from payroll" refusal) only has a sentence, so show it in the banner.
    const parsed = parseApiError(e, 'Could not save this expense.')
    expenseErrors.value = parsed.errors
    if (!Object.keys(parsed.errors).length) error.value = parsed.message
  }
}

async function deleteExpense(expense) {
  if (!window.confirm('Delete this expense?')) return
  try {
    await api.delete(`/expenses/${expense.id}`)
    await loadExpenses()
  } catch (e) {
    error.value = parseApiError(e, 'Could not delete this expense.').message
  }
}

const expenseTotal = computed(() =>
  expenses.value.reduce((sum, row) => sum + Number(row.amount || 0), 0)
)
```

Extend `onMounted` to load both: `onMounted(async () => { await loadRuns(); await loadExpenses() })`.

- [ ] **Step 2: Add the expenses tab markup**

After the payroll `<section>` in the template:

```vue
    <section v-if="tab === 'expenses'" class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">From</label>
          <input v-model="expenseFilters.from" type="date" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadExpenses" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">To</label>
          <input v-model="expenseFilters.to" type="date" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadExpenses" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Category</label>
          <select v-model="expenseFilters.category" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadExpenses">
            <option value="">All</option>
            <option v-for="c in EXPENSE_CATEGORIES" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <button class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700" @click="openExpenseModal()">
          Add expense
        </button>
      </div>

      <div class="overflow-hidden rounded-xl border border-slate-200">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-2">Date</th>
              <th class="px-4 py-2">Category</th>
              <th class="px-4 py-2">Note</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="expense in expenses" :key="expense.id">
              <td class="px-4 py-2">{{ expense.expense_date }}</td>
              <td class="px-4 py-2 capitalize">{{ expense.category }}</td>
              <td class="px-4 py-2 text-slate-500">{{ expense.note || '—' }}</td>
              <td class="px-4 py-2 text-right">{{ money(expense.amount) }}</td>
              <td class="px-4 py-2 text-right">
                <span v-if="expense.is_locked" class="text-xs text-slate-400">From payroll</span>
                <template v-else>
                  <button class="text-sm text-indigo-600 hover:underline" @click="openExpenseModal(expense)">Edit</button>
                  <button class="ml-3 text-sm text-rose-600 hover:underline" @click="deleteExpense(expense)">Delete</button>
                </template>
              </td>
            </tr>
            <tr v-if="!expenses.length">
              <td colspan="5" class="px-4 py-6 text-center text-slate-500">No expenses in this range.</td>
            </tr>
          </tbody>
          <tfoot v-if="expenses.length" class="bg-slate-50">
            <tr>
              <td colspan="3" class="px-4 py-2 text-right text-sm font-medium text-slate-600">Total</td>
              <td class="px-4 py-2 text-right text-sm font-semibold text-slate-900">{{ money(expenseTotal) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <Modal
      v-if="expenseModalOpen"
      :title="editingExpenseId ? 'Edit expense' : 'Add expense'"
      @close="expenseModalOpen = false"
    >
      <div class="space-y-4">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Category</label>
          <select v-model="expenseForm.category" class="w-full rounded-lg border border-slate-300 px-3 py-2.5">
            <option v-for="c in EXPENSE_CATEGORIES" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Date</label>
          <input v-model="expenseForm.expense_date" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" />
          <p v-if="expenseErrors.expense_date" class="mt-1 text-sm text-rose-600">{{ expenseErrors.expense_date[0] }}</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Amount</label>
          <input v-model="expenseForm.amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" />
          <p v-if="expenseErrors.amount" class="mt-1 text-sm text-rose-600">{{ expenseErrors.amount[0] }}</p>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">Note</label>
          <input v-model="expenseForm.note" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2.5" />
        </div>
      </div>
      <template #footer>
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm" @click="expenseModalOpen = false">Cancel</button>
        <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" @click="saveExpense">Save</button>
      </template>
    </Modal>
```

`Modal.vue` renders the title bar and close button itself and puts `#footer` content in a bordered action row — the same shape `StaffView.vue` uses at its `<Modal v-if="showForm" …>`.

**Deliberate deviation from the spec:** the modal has no branch selector. The spec listed one, but the free plan allows a single branch, so the field would be a required-looking choice with one option. `branch_id` stays supported by the API and `ExpenseResource`; the selector goes in when multi-branch plans ship.

- [ ] **Step 3: Verify in the browser**

Add a rent expense for today; it appears with the total. Edit it; the amount changes. Filter to a category with no rows; the empty state shows. Finalize a payroll run in the Payroll tab, then return: a "From payroll" row exists with no Edit/Delete buttons.

- [ ] **Step 4: Commit**

```bash
cd frontend && git add src/views/FinanceView.vue
git commit -m "feat(finance): log and review expenses from the dashboard"
```

---

### Task 10: Finance screen — profit tab

**Files:**
- Modify: `frontend/src/views/FinanceView.vue`

**Interfaces:**
- Consumes: `data.profit` on `GET /api/reports` (Task 6).
- Produces: the `profit` tab inside `FinanceView.vue`.

- [ ] **Step 1: Add the profit state**

In `<script setup>`:

```js
const profit = ref(null)
const profitRange = reactive({ from: startOfMonth(), to: today() })

async function loadProfit() {
  error.value = ''
  try {
    const { data } = await api.get('/reports', { params: { from: profitRange.from, to: profitRange.to } })
    profit.value = data.data.profit
  } catch (e) {
    error.value = parseApiError(e, 'Could not load profit.').message
  }
}
```

Load it when the tab is first opened rather than on mount — the reports endpoint is the heaviest call on this screen:

```js
watch(tab, (value) => {
  if (value === 'profit' && !profit.value) loadProfit()
})
```

Add `watch` to the `vue` import.

- [ ] **Step 2: Add the profit tab markup**

After the expenses `<section>`:

```vue
    <section v-if="tab === 'profit'" class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">From</label>
          <input v-model="profitRange.from" type="date" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadProfit" />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-slate-700">To</label>
          <input v-model="profitRange.to" type="date" class="rounded-lg border border-slate-300 px-3 py-2.5" @change="loadProfit" />
        </div>
      </div>

      <div v-if="profit" class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Earned</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ money(profit.earned) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Expenses</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ money(profit.expenses_total) }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 p-4">
          <p class="text-xs uppercase tracking-wide text-slate-500">Net profit</p>
          <p class="mt-1 text-2xl font-semibold" :class="profit.net_profit >= 0 ? 'text-emerald-600' : 'text-rose-600'">
            {{ money(profit.net_profit) }}
          </p>
        </div>
      </div>

      <div v-if="profit?.expenses_by_category.length" class="overflow-hidden rounded-xl border border-slate-200">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th class="px-4 py-2">Category</th>
              <th class="px-4 py-2 text-right">Amount</th>
              <th class="px-4 py-2 text-right">Share</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in profit.expenses_by_category" :key="row.category">
              <td class="px-4 py-2 capitalize">{{ row.category }}</td>
              <td class="px-4 py-2 text-right">{{ money(row.amount) }}</td>
              <td class="px-4 py-2 text-right text-slate-500">{{ row.share_pct }}%</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-else-if="profit" class="rounded-lg border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">
        No expenses in this range — net profit is everything earned.
      </p>
    </section>
```

- [ ] **Step 3: Verify in the browser**

With a finalized payroll run and a rent expense in the current month, the Profit tab shows earned, expenses (including the payroll salary row), and a net figure that is green when positive and red when negative. Widen the range past the month end and the numbers grow.

- [ ] **Step 4: Run both suites**

Run: `cd frontend && npm run test:unit` and `cd backend && php artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add src/views/FinanceView.vue
git commit -m "feat(finance): show net profit against expenses"
```

---

## Verification checklist

After Task 10, confirm end to end as an owner:

1. Staff page → set Rima to "Salary + commission", 1000 salary, 25% rate.
2. Complete an appointment for Rima this month at a known price.
3. Finance → Payroll → open the current month → her line shows the booking, earned, 25% commission, and 1000 salary.
4. Edit her salary down → the header total follows.
5. Finalize → the run locks; Expenses shows a locked "Payroll — <Month>" salary row that cannot be edited or deleted.
6. Add a rent expense → Profit shows earned, both expenses, and the net.
7. Delete the payroll run → its salary expense disappears from the Expenses tab and the Profit total drops accordingly.
8. Log in as a manager → no Finance sidebar entry, `/finance` is not reachable, `/api/reports` still loads without a `profit` block, and the staff record shows no pay fields.
