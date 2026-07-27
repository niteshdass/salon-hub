# Customer Accounts & Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give salon customers one platform-wide passwordless account to log in, see all their bookings across every salon, cancel/reschedule upcoming bookings, and review completed ones.

**Architecture:** A new global `CustomerAccount` identity (Sanctum, own guard) sits above the existing per-salon `customers` rows, linked by a nullable `customer_account_id`. Customer API routes run WITHOUT the `tenant` middleware, so the `BelongsToOrganization` global scope is inert and one query can read across salons — which means every customer query MUST be manually filtered by the account's own rows. Passwordless login = email → 6-digit OTP. Cancel/reschedule/review reuse the existing booking engine after binding the target appointment's organization as the current tenant.

**Tech Stack:** Laravel 12 / PHP 8.4, Sanctum (multi-guard), Vue 3 + Pinia + Tailwind + Vite. Reuses `SlotGenerator`, `AppointmentScheduler`, `BookingNotifier`, `StoreReviewRequest`.

Spec: `backend/docs/superpowers/specs/2026-07-27-customer-accounts-dashboard-design.md`

## Global Constraints

- **PHP 8.4 / Laravel 12.** Match existing code style.
- **Multi-tenancy is sacred.** Customer routes are cross-org by design; EVERY customer data query MUST be filtered by the authenticated account's own `customers` rows (`customer_account_id = $account->id`). No query may return a row the account does not own.
- **Guard separation.** A `User` (staff) token MUST be rejected on customer routes; a `CustomerAccount` token MUST be rejected on staff routes. Enforced by pinning each Sanctum guard to its provider model (`config/auth.php`). Both directions are tested and MUST pass.
- **No secrets in source.** OTP codes stored hashed (`Hash::make`), never plaintext.
- **TDD.** Every endpoint/behavior gets a failing test first. Feature tests use real Sanctum bearer tokens (`$model->createToken('x')->plainTextToken`), `RefreshDatabase`, and `Mail::fake()` for email assertions.
- **Passwordless — no password column** on `customer_accounts`.
- **Reuse, don't fork** the booking engine; the only new authorization is account ownership of the appointment.

### Test fixture conventions (existing, reuse verbatim)

`Organization::create(['uuid'=>(string)Str::uuid(),'name'=>...,'slug'=>...,'email'=>...,'subscription_plan'=>'free','status'=>'active'])`; `Branch::create(['organization_id'=>$org->id,'name'=>'Main'])`; `Service::create(['organization_id'=>$org->id,'name'=>...,'duration'=>30,'price'=>25,'status'=>'active'])`; staff `User::create([...,'role'=>'staff','status'=>'active'])` + `StaffProfile::create(['user_id'=>...,'designation'=>'Stylist','working_days_json'=>[1,2,3,4,5],'working_hours_json'=>['start'=>'09:00','end'=>'17:00']])`; `Customer::create(['organization_id'=>$org->id,'name'=>...,'phone'=>...,'email'=>...])`; `Appointment::create(['organization_id'=>$org->id,'public_token'=>(string)Str::uuid(),'branch_id'=>...,'customer_id'=>...,'staff_id'=>...,'service_id'=>...,'booking_date'=>'2026-07-15','start_time'=>'10:00:00','end_time'=>'10:30:00','price'=>25,'status'=>'completed'])`. In plain (non-HTTP) test setup no tenant is bound, so `organization_id` is passed explicitly.

---

## File Structure

**Backend — create:**
- `database/migrations/2026_07_27_100000_create_customer_accounts_table.php`
- `database/migrations/2026_07_27_100001_create_customer_login_codes_table.php`
- `database/migrations/2026_07_27_100002_add_customer_account_id_to_customers_table.php`
- `app/Models/CustomerAccount.php`
- `app/Models/CustomerLoginCode.php`
- `app/Mail/CustomerLoginCodeMail.php`
- `resources/views/mail/customer/login-code.blade.php`
- `app/Http/Controllers/Customer/AuthController.php`
- `app/Http/Controllers/Customer/BookingController.php`
- `tests/Feature/Customer/AuthTest.php`
- `tests/Feature/Customer/LinkingTest.php`
- `tests/Feature/Customer/BookingsTest.php`

**Backend — modify:**
- `config/auth.php` (guards + providers)
- `app/Models/Customer.php` (`customer_account_id` fillable + `account()` relation)
- `app/Models/Appointment.php` (`review()` relation + `isChangeable()`/`isCompleted()` helpers)
- `app/Http/Controllers/Public/BookingController.php` (`book()` fresh-attach)
- `routes/api.php` (customer route group)

**Frontend — create:**
- `frontend/src/lib/customerApi.js`
- `frontend/src/stores/customerAuth.js`
- `frontend/src/layouts/CustomerLayout.vue`
- `frontend/src/views/CustomerLoginView.vue`
- `frontend/src/views/CustomerDashboardView.vue`

**Frontend — modify:**
- `frontend/src/router/index.js` (routes + `requiresCustomerAuth` guard)
- `frontend/src/views/SalonSiteView.vue` (entry link)

---

## Task 1: Data model foundation

**Files:**
- Create: the three migrations above, `app/Models/CustomerAccount.php`, `app/Models/CustomerLoginCode.php`
- Modify: `app/Models/Customer.php`
- Test: `tests/Feature/Customer/AuthTest.php` (model-level tests only in this task)

**Interfaces:**
- Produces: `CustomerAccount` (HasApiTokens; `$fillable=['name','email','phone','email_verified_at']`; cast `email_verified_at`=>datetime; `customers()` HasMany), `CustomerLoginCode` (`$fillable=['email','code_hash','expires_at','attempts','consumed_at']`; casts `expires_at`,`consumed_at`=>datetime, `attempts`=>int; `scopeActive`), `customers.customer_account_id` nullable FK + `Customer::account()` BelongsTo.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Customer/AuthTest.php`

```php
<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerLoginCode;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug = 'acme'): Organization
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

    public function test_customer_account_can_be_created_and_issue_a_token(): void
    {
        $account = CustomerAccount::create(['name' => 'Jane', 'email' => 'jane@x.test', 'phone' => '555']);
        $token = $account->createToken('customer')->plainTextToken;

        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => CustomerAccount::class,
            'tokenable_id' => $account->id,
        ]);
    }

    public function test_login_code_active_scope_excludes_expired_and_consumed(): void
    {
        $fresh = CustomerLoginCode::create(['email' => 'a@x.test', 'code_hash' => Hash::make('111111'), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
        CustomerLoginCode::create(['email' => 'a@x.test', 'code_hash' => Hash::make('222222'), 'expires_at' => now()->subMinute(), 'attempts' => 0]);
        CustomerLoginCode::create(['email' => 'a@x.test', 'code_hash' => Hash::make('333333'), 'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'consumed_at' => now()]);

        $active = CustomerLoginCode::where('email', 'a@x.test')->active()->get();

        $this->assertCount(1, $active);
        $this->assertSame($fresh->id, $active->first()->id);
    }

    public function test_customer_links_to_account(): void
    {
        $org = $this->makeOrg();
        $account = CustomerAccount::create(['email' => 'jane@x.test']);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Jane', 'phone' => '555', 'email' => 'jane@x.test', 'customer_account_id' => $account->id]);

        $this->assertSame($account->id, $customer->fresh()->account->id);
        $this->assertTrue($account->customers()->where('id', $customer->id)->exists());
    }
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `php artisan test tests/Feature/Customer/AuthTest.php`
Expected: FAIL — `Class "App\Models\CustomerAccount" not found`.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_07_27_100000_create_customer_accounts_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_accounts');
    }
};
```

`database/migrations/2026_07_27_100001_create_customer_login_codes_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_login_codes');
    }
};
```

`database/migrations/2026_07_27_100002_add_customer_account_id_to_customers_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('customer_account_id')->nullable()->after('organization_id')
                ->constrained('customer_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_account_id');
        });
    }
};
```

- [ ] **Step 4: Write the models**

`app/Models/CustomerAccount.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform-wide customer identity. Global — NOT tenant-scoped. One account
 * links to many per-salon `customers` rows via customer_account_id.
 */
class CustomerAccount extends Model
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'email_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
```

`app/Models/CustomerLoginCode.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A single passwordless login code, keyed by email (the account may not exist
 * yet at request time). Codes are stored hashed and are single-use.
 */
class CustomerLoginCode extends Model
{
    protected $fillable = [
        'email',
        'code_hash',
        'expires_at',
        'attempts',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** Not yet consumed and not yet expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}
```

- [ ] **Step 5: Modify `app/Models/Customer.php`**

Add `'customer_account_id'` to `$fillable` (after `'organization_id'`), and add the relation + import:
```php
// add import near the others:
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// add method inside the class:
    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
```

- [ ] **Step 6: Run the test — verify it passes**

Run: `php artisan test tests/Feature/Customer/AuthTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all pass (existing + 3 new).

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models/CustomerAccount.php app/Models/CustomerLoginCode.php app/Models/Customer.php tests/Feature/Customer/AuthTest.php
git commit -m "feat: customer account + login-code models and schema"
```

---

## Task 2: Passwordless OTP auth + guard separation

**Files:**
- Modify: `config/auth.php`, `routes/api.php`
- Create: `app/Mail/CustomerLoginCodeMail.php`, `resources/views/mail/customer/login-code.blade.php`, `app/Http/Controllers/Customer/AuthController.php`
- Test: `tests/Feature/Customer/AuthTest.php` (append endpoint tests)

**Interfaces:**
- Consumes: `CustomerAccount`, `CustomerLoginCode` (Task 1).
- Produces: `POST /api/customer/auth/request-code`, `POST /api/customer/auth/verify-code`, `GET /api/customer/auth/me`, `POST /api/customer/auth/logout`. Guard `customer` (provider `customers`). `verify-code` returns `{token, account:{id,name,email,phone}}`. **Auto-claim is added in Task 3, not here.**

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/Customer/AuthTest.php`

Add imports at top of the file: `use App\Models\User;`, `use Illuminate\Support\Facades\Mail;`, `use App\Mail\CustomerLoginCodeMail;`. Add a helper and tests inside the class:

```php
    /** Request a code, then read the plaintext back off the DB row for the assertion. */
    private function requestCode(string $email): void
    {
        $this->postJson('/api/customer/auth/request-code', ['email' => $email])->assertOk();
    }

    public function test_request_code_stores_hashed_code_and_sends_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/customer/auth/request-code', ['email' => 'jane@x.test'])
            ->assertOk();

        $row = CustomerLoginCode::where('email', 'jane@x.test')->active()->first();
        $this->assertNotNull($row);
        $this->assertNotSame('', $row->code_hash);
        Mail::assertSent(CustomerLoginCodeMail::class, fn ($m) => $m->hasTo('jane@x.test'));
    }

    public function test_request_code_returns_generic_ok_and_hashes_not_plaintext(): void
    {
        Mail::fake();
        $this->postJson('/api/customer/auth/request-code', ['email' => 'jane@x.test'])->assertOk();

        $row = CustomerLoginCode::where('email', 'jane@x.test')->first();
        // Six-digit code is never stored in the clear.
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', $row->code_hash);
    }

    /** Drives the real flow: capture the code by faking mail and reading the mailable. */
    private function loginCodeFor(string $email): string
    {
        Mail::fake();
        $this->postJson('/api/customer/auth/request-code', ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(CustomerLoginCodeMail::class, function ($m) use ($email, &$code) {
            if ($m->hasTo($email)) { $code = $m->code; return true; }
            return false;
        });
        return $code;
    }

    public function test_verify_code_creates_verified_account_and_returns_token(): void
    {
        $code = $this->loginCodeFor('jane@x.test');

        $res = $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code]);

        $res->assertOk()->assertJsonStructure(['token', 'account' => ['id', 'name', 'email', 'phone']]);
        $account = CustomerAccount::where('email', 'jane@x.test')->first();
        $this->assertNotNull($account);
        $this->assertNotNull($account->email_verified_at);
        $this->assertDatabaseHas('customer_login_codes', ['email' => 'jane@x.test']);
        $this->assertNotNull(CustomerLoginCode::where('email', 'jane@x.test')->first()->consumed_at);
    }

    public function test_verify_code_is_idempotent_reuses_same_account(): void
    {
        $c1 = $this->loginCodeFor('jane@x.test');
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $c1])->assertOk();
        $c2 = $this->loginCodeFor('jane@x.test');
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $c2])->assertOk();

        $this->assertSame(1, CustomerAccount::where('email', 'jane@x.test')->count());
    }

    public function test_verify_code_rejects_wrong_code_and_increments_attempts(): void
    {
        $this->loginCodeFor('jane@x.test');
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => '000000'])
            ->assertStatus(422);
        $this->assertSame(1, CustomerLoginCode::where('email', 'jane@x.test')->active()->first()->attempts);
    }

    public function test_verify_code_rejects_expired_code(): void
    {
        CustomerLoginCode::create(['email' => 'jane@x.test', 'code_hash' => \Hash::make('123456'), 'expires_at' => now()->subMinute(), 'attempts' => 0]);
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => '123456'])
            ->assertStatus(422);
    }

    public function test_verify_code_locks_after_five_attempts(): void
    {
        $code = $this->loginCodeFor('jane@x.test');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => '000000'])->assertStatus(422);
        }
        // Sixth try, even with the CORRECT code, is locked out.
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])
            ->assertStatus(429);
    }

    public function test_consumed_code_cannot_be_reused(): void
    {
        $code = $this->loginCodeFor('jane@x.test');
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])->assertOk();
        $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])->assertStatus(422);
    }

    public function test_me_and_logout(): void
    {
        $code = $this->loginCodeFor('jane@x.test');
        $token = $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])->json('token');

        $this->withToken($token)->getJson('/api/customer/auth/me')
            ->assertOk()->assertJsonPath('account.email', 'jane@x.test');

        $this->withToken($token)->postJson('/api/customer/auth/logout')->assertOk();
        // Token revoked.
        $this->withToken($token)->getJson('/api/customer/auth/me')->assertUnauthorized();
    }

    public function test_guard_separation_staff_token_rejected_on_customer_route(): void
    {
        $org = $this->makeOrg();
        $staff = User::create(['organization_id' => $org->id, 'name' => 'Owner', 'email' => 'o@acme.test', 'password' => 'secret1234', 'role' => 'owner', 'status' => 'active']);
        $staffToken = $staff->createToken('api')->plainTextToken;

        $this->withToken($staffToken)->getJson('/api/customer/auth/me')->assertUnauthorized();
    }

    public function test_guard_separation_customer_token_rejected_on_staff_route(): void
    {
        $code = $this->loginCodeFor('jane@x.test');
        $customerToken = $this->postJson('/api/customer/auth/verify-code', ['email' => 'jane@x.test', 'code' => $code])->json('token');

        // A staff-only route (tenant group) must reject the customer token.
        $this->withToken($customerToken)->getJson('/api/dashboard')->assertUnauthorized();
    }
```

Note: the staff-token-against-a-customer-*data*-route assertion (`/api/customer/bookings`) lives in Task 4's suite, where that route exists — this task asserts guard separation against `/api/customer/auth/me`, which exists here.

- [ ] **Step 2: Run — verify failure**

Run: `php artisan test tests/Feature/Customer/AuthTest.php`
Expected: FAIL — 404/500 on the new endpoints (routes/controller absent).

- [ ] **Step 3: Configure guards** — `config/auth.php`

Replace the `guards` array and add the `customers` provider:
```php
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Pin the staff Sanctum guard to the users provider so a CustomerAccount
        // token is rejected here (Sanctum Guard::hasValidProvider).
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        // Customer Sanctum guard — only accepts CustomerAccount tokens.
        'customer' => [
            'driver' => 'sanctum',
            'provider' => 'customers',
        ],
    ],
```
And in `providers`, add below `users`:
```php
        'customers' => [
            'driver' => 'eloquent',
            'model' => App\Models\CustomerAccount::class,
        ],
```

- [ ] **Step 4: Mailable + view**

`app/Mail/CustomerLoginCodeMail.php`:
```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Delivers a one-time passwordless login code. Not queued: the customer is
 * waiting on the code, so it is sent inline.
 */
class CustomerLoginCodeMail extends Mailable
{
    public function __construct(public string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your SalonHub login code');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.customer.login-code',
            with: ['code' => $this->code],
        );
    }
}
```

`resources/views/mail/customer/login-code.blade.php`:
```blade
@component('mail::message')
# Your login code

Use this code to sign in. It expires in 10 minutes.

@component('mail::panel')
# {{ $code }}
@endcomponent

If you didn't request this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
```

- [ ] **Step 5: Controller** — `app/Http/Controllers/Customer/AuthController.php`

```php
<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Mail\CustomerLoginCodeMail;
use App\Models\CustomerAccount;
use App\Models\CustomerLoginCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Passwordless customer authentication. No tenant is bound on these routes,
 * so nothing here is tenant-scoped — the account is a global identity.
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        CustomerLoginCode::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        Mail::to($email)->send(new CustomerLoginCodeMail($code));

        // Generic response — never reveal whether an account exists.
        return response()->json(['message' => 'If that email is valid, a code has been sent.']);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);
        $email = strtolower($data['email']);

        $row = CustomerLoginCode::where('email', $email)->active()->latest('id')->first();

        if (! $row) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            return response()->json(['message' => 'Too many attempts. Request a new code.'], 429);
        }

        if (! Hash::check($data['code'], $row->code_hash)) {
            $row->increment('attempts');

            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $row->update(['consumed_at' => now()]);

        $account = CustomerAccount::firstOrCreate(['email' => $email]);
        $account->forceFill(['email_verified_at' => now()])->save();

        // Task 3 inserts the auto-claim call here.

        $token = $account->createToken('customer')->plainTextToken;

        return response()->json([
            'token' => $token,
            'account' => $this->accountPayload($account),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['account' => $this->accountPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** @return array<string, mixed> */
    private function accountPayload(CustomerAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'phone' => $account->phone,
        ];
    }
}
```

- [ ] **Step 6: Routes** — `routes/api.php`

Add the import near the other controller imports:
```php
use App\Http\Controllers\Customer\AuthController as CustomerAuthController;
```
Add this group AFTER the `public/{org}` group (top-level, NOT inside `tenant`):
```php
// Platform-wide customer accounts. No `tenant` middleware: the account is a
// global identity, so the tenant scope is intentionally inert and every query
// filters by the account's own customers rows.
Route::prefix('customer')->group(function () {
    Route::post('auth/request-code', [CustomerAuthController::class, 'requestCode'])->middleware('throttle:6,1');
    Route::post('auth/verify-code', [CustomerAuthController::class, 'verifyCode'])->middleware('throttle:10,1');

    Route::middleware('auth:customer')->group(function () {
        Route::get('auth/me', [CustomerAuthController::class, 'me']);
        Route::post('auth/logout', [CustomerAuthController::class, 'logout']);
    });
});
```

- [ ] **Step 7: Run the auth tests**

Run: `php artisan test tests/Feature/Customer/AuthTest.php`
Expected: PASS (all).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add config/auth.php app/Mail/CustomerLoginCodeMail.php resources/views/mail/customer app/Http/Controllers/Customer/AuthController.php routes/api.php tests/Feature/Customer/AuthTest.php
git commit -m "feat: passwordless customer OTP auth + guard separation"
```

---

## Task 3: Identity linking — auto-claim + fresh-booking attach

**Files:**
- Modify: `app/Http/Controllers/Customer/AuthController.php` (auto-claim in `verifyCode`), `app/Http/Controllers/Public/BookingController.php` (`book()` attach)
- Test: `tests/Feature/Customer/LinkingTest.php`

**Interfaces:**
- Consumes: verify-code endpoint (Task 2), `Customer`, `CustomerAccount`.
- Produces: after a successful verify, all `customers` rows (any org) with a matching email are linked; a public `book()` whose customer email matches a verified account links immediately.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Customer/LinkingTest.php`

```php
<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerLoginCode;
use App\Models\Organization;
use App\Mail\CustomerLoginCodeMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinkingTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug,
            'email' => "owner@{$slug}.test", 'subscription_plan' => 'free', 'status' => 'active',
        ]);
    }

    private function loginCodeFor(string $email): string
    {
        Mail::fake();
        $this->postJson('/api/customer/auth/request-code', ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(CustomerLoginCodeMail::class, function ($m) use ($email, &$code) {
            if ($m->hasTo($email)) { $code = $m->code; return true; }
            return false;
        });
        return $code;
    }

    private function verify(string $email): void
    {
        $code = $this->loginCodeFor($email);
        $this->postJson('/api/customer/auth/verify-code', ['email' => $email, 'code' => $code])->assertOk();
    }

    public function test_verify_claims_matching_customer_rows_across_salons(): void
    {
        $orgA = $this->makeOrg('acme');
        $orgB = $this->makeOrg('glow');
        $mine1 = Customer::create(['organization_id' => $orgA->id, 'name' => 'Jane', 'phone' => '111', 'email' => 'jane@x.test']);
        $mine2 = Customer::create(['organization_id' => $orgB->id, 'name' => 'Jane', 'phone' => '222', 'email' => 'jane@x.test']);
        $notMine = Customer::create(['organization_id' => $orgA->id, 'name' => 'Other', 'phone' => '333', 'email' => 'other@x.test']);

        $this->verify('jane@x.test');

        $account = CustomerAccount::where('email', 'jane@x.test')->first();
        $this->assertSame($account->id, $mine1->fresh()->customer_account_id);
        $this->assertSame($account->id, $mine2->fresh()->customer_account_id);
        $this->assertNull($notMine->fresh()->customer_account_id);
    }

    public function test_verify_is_idempotent_and_links_rows_created_since_last_login(): void
    {
        $org = $this->makeOrg('acme');
        $this->verify('jane@x.test');
        $account = CustomerAccount::where('email', 'jane@x.test')->first();

        // A row created after the first login is picked up on the next verify.
        $late = Customer::create(['organization_id' => $org->id, 'name' => 'Jane', 'phone' => '444', 'email' => 'jane@x.test']);
        $this->verify('jane@x.test');

        $this->assertSame($account->id, $late->fresh()->customer_account_id);
        $this->assertSame(1, CustomerAccount::where('email', 'jane@x.test')->count());
    }
}
```

Note: the public-`book()` attach is exercised end-to-end in Task 4's `BookingsTest` (which has the full booking fixtures). This task's test proves the claim path.

- [ ] **Step 2: Run — verify failure**

Run: `php artisan test tests/Feature/Customer/LinkingTest.php`
Expected: FAIL — rows not linked (`customer_account_id` still null).

- [ ] **Step 3: Add auto-claim to `verifyCode`**

In `app/Http/Controllers/Customer/AuthController.php`, replace the `// Task 3 inserts the auto-claim call here.` comment with:
```php
        // Link every per-salon customer row that shares this verified email,
        // across all organizations. No tenant is bound here, so the tenant
        // global scope is inert and this reaches all salons. Idempotent via
        // the whereNull guard; also backfill the account name from a row.
        Customer::whereNull('customer_account_id')
            ->where('email', $email)
            ->update(['customer_account_id' => $account->id]);

        if (blank($account->name)) {
            $name = Customer::where('customer_account_id', $account->id)->whereNotNull('name')->value('name');
            if ($name) {
                $account->forceFill(['name' => $name])->save();
            }
        }
```
Add `use App\Models\Customer;` to the imports.

- [ ] **Step 4: Add the fresh-booking attach** — `app/Http/Controllers/Public/BookingController.php`

Inside `book()`, in the `DB::transaction` closure, right AFTER the `$customer = Customer::firstOrCreate(...)` block (before `$appointment = Appointment::create(...)`), add:
```php
            // If this customer's email belongs to a verified platform account,
            // link the row now so the booking shows on their dashboard without
            // waiting for a re-login.
            if ($customer->email && ! $customer->customer_account_id) {
                $accountId = CustomerAccount::whereNotNull('email_verified_at')
                    ->where('email', $customer->email)->value('id');
                if ($accountId) {
                    $customer->forceFill(['customer_account_id' => $accountId])->save();
                }
            }
```
Add `use App\Models\CustomerAccount;` to the imports.

- [ ] **Step 5: Run the linking test — verify pass**

Run: `php artisan test tests/Feature/Customer/LinkingTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite**

Run: `php artisan test`
Expected: all green (except the Task-4-pending `/api/customer/bookings` assertion from Task 2).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Customer/AuthController.php app/Http/Controllers/Public/BookingController.php tests/Feature/Customer/LinkingTest.php
git commit -m "feat: email-verified auto-claim + fresh-booking account attach"
```

---

## Task 4: Dashboard bookings read

**Files:**
- Create: `app/Http/Controllers/Customer/BookingController.php` (index only in this task)
- Modify: `app/Models/Appointment.php` (add `review()` relation), `routes/api.php` (bookings route)
- Test: `tests/Feature/Customer/BookingsTest.php`

**Interfaces:**
- Consumes: `auth:customer` (Task 2), linking (Task 3).
- Produces: `GET /api/customer/bookings` → `{ data: { upcoming: Booking[], past: Booking[] } }`. `Booking` = `{id, salon:{id,name,slug}, service, staff, branch, booking_date, start_time, end_time, status, price, amount_paid, balance_due, can_manage, can_review, review}`. Adds `Appointment::review(): HasOne`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Customer/BookingsTest.php`

```php
<?php

namespace Tests\Feature\Customer;

use App\Mail\CustomerLoginCodeMail;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $slug): Organization
    {
        return Organization::create([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug,
            'email' => "owner@{$slug}.test", 'subscription_plan' => 'free', 'status' => 'active',
        ]);
    }

    private function makeStaff(Organization $org, string $name = 'Sam'): User
    {
        $staff = User::create(['organization_id' => $org->id, 'name' => $name, 'email' => Str::random(6)."@{$org->slug}.test", 'password' => 'secret1234', 'role' => 'staff', 'status' => 'active']);
        StaffProfile::create(['user_id' => $staff->id, 'designation' => 'Stylist', 'working_days_json' => [1, 2, 3, 4, 5], 'working_hours_json' => ['start' => '09:00', 'end' => '17:00']]);
        return $staff;
    }

    /** An account with one linked customer row per given org, and a token. */
    private function account(string $email): CustomerAccount
    {
        return CustomerAccount::create(['name' => 'Jane', 'email' => $email, 'email_verified_at' => now()]);
    }

    private function tokenFor(CustomerAccount $account): string
    {
        return $account->createToken('customer')->plainTextToken;
    }

    private function makeBooking(Organization $org, CustomerAccount $account, array $o = []): Appointment
    {
        $branch = Branch::create(['organization_id' => $org->id, 'name' => 'Main']);
        $service = Service::create(['organization_id' => $org->id, 'name' => $o['service'] ?? 'Haircut', 'duration' => 30, 'price' => $o['price'] ?? 40, 'status' => 'active']);
        $staff = $this->makeStaff($org, $o['staff'] ?? 'Sam');
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Jane', 'phone' => Str::random(6), 'email' => $account->email, 'customer_account_id' => $account->id]);

        return Appointment::create([
            'organization_id' => $org->id, 'public_token' => (string) Str::uuid(),
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'staff_id' => $staff->id, 'service_id' => $service->id,
            'booking_date' => $o['date'] ?? '2026-08-10', 'start_time' => $o['start_time'] ?? '10:00:00', 'end_time' => '10:30:00',
            'price' => $o['price'] ?? 40, 'status' => $o['status'] ?? 'confirmed',
        ]);
    }

    public function test_lists_own_bookings_split_upcoming_and_past(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        // Upcoming: future + confirmed. Past: completed.
        $this->makeBooking($org, $account, ['date' => '2999-01-01', 'status' => 'confirmed', 'service' => 'Future Cut']);
        $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed', 'service' => 'Old Cut']);

        $res = $this->withToken($this->tokenFor($account))->getJson('/api/customer/bookings');

        $res->assertOk()
            ->assertJsonStructure(['data' => [
                'upcoming' => [['id', 'salon' => ['id', 'name', 'slug'], 'service', 'staff', 'branch', 'booking_date', 'start_time', 'end_time', 'status', 'price', 'amount_paid', 'balance_due', 'can_manage', 'can_review', 'review']],
                'past' => [['id', 'service']],
            ]]);
        $this->assertCount(1, $res->json('data.upcoming'));
        $this->assertCount(1, $res->json('data.past'));
        $this->assertSame('Future Cut', $res->json('data.upcoming.0.service'));
        $this->assertTrue($res->json('data.upcoming.0.can_manage'));
    }

    public function test_aggregates_across_salons(): void
    {
        $orgA = $this->makeOrg('acme');
        $orgB = $this->makeOrg('glow');
        $account = $this->account('jane@x.test');
        $this->makeBooking($orgA, $account, ['date' => '2999-01-01']);
        $this->makeBooking($orgB, $account, ['date' => '2999-02-02']);

        $res = $this->withToken($this->tokenFor($account))->getJson('/api/customer/bookings');
        $res->assertOk();
        $this->assertCount(2, $res->json('data.upcoming'));
        $slugs = collect($res->json('data.upcoming'))->pluck('salon.slug')->sort()->values()->all();
        $this->assertSame(['acme', 'glow'], $slugs);
    }

    public function test_isolation_account_sees_only_its_own_bookings(): void
    {
        $org = $this->makeOrg('acme');
        $mine = $this->account('jane@x.test');
        $theirs = $this->account('bob@x.test');
        $this->makeBooking($org, $mine, ['date' => '2999-01-01', 'service' => 'Mine']);
        $this->makeBooking($org, $theirs, ['date' => '2999-01-01', 'service' => 'Theirs']);

        $res = $this->withToken($this->tokenFor($mine))->getJson('/api/customer/bookings');
        $res->assertOk();
        $services = collect($res->json('data.upcoming'))->pluck('service')->all();
        $this->assertSame(['Mine'], $services);
    }

    public function test_staff_token_rejected_on_customer_bookings_route(): void
    {
        $org = $this->makeOrg('acme');
        $staff = User::create(['organization_id' => $org->id, 'name' => 'Owner', 'email' => 'o@acme.test', 'password' => 'secret1234', 'role' => 'owner', 'status' => 'active']);
        $this->withToken($staff->createToken('api')->plainTextToken)->getJson('/api/customer/bookings')
            ->assertUnauthorized();
    }

    public function test_completed_without_review_can_be_reviewed(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);

        $res = $this->withToken($this->tokenFor($account))->getJson('/api/customer/bookings');
        $this->assertTrue($res->json('data.past.0.can_review'));
        $this->assertNull($res->json('data.past.0.review'));
    }

    public function test_reviewed_booking_shows_review_and_cannot_review_again(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);
        Review::create(['organization_id' => $org->id, 'appointment_id' => $appt->id, 'staff_id' => $appt->staff_id, 'rating' => 5, 'comment' => 'Great', 'reviewer_name' => 'Jane', 'status' => 'published']);

        $res = $this->withToken($this->tokenFor($account))->getJson('/api/customer/bookings');
        $this->assertFalse($res->json('data.past.0.can_review'));
        $this->assertSame(5, $res->json('data.past.0.review.rating'));
    }
}
```

- [ ] **Step 2: Run — verify failure**

Run: `php artisan test tests/Feature/Customer/BookingsTest.php`
Expected: FAIL — 404 (route/controller absent).

- [ ] **Step 3: Add `review()` relation to `Appointment`**

In `app/Models/Appointment.php` add `use Illuminate\Database\Eloquent\Relations\HasOne;` and:
```php
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
```
Also add the status helpers used by later tasks (do it now while in this file):
```php
    /** Still customer-editable (pending or confirmed). */
    public function isChangeable(): bool
    {
        return in_array($this->status, [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED], true);
    }

    /** A finished visit — the only state a customer may review. */
    public function isCompleted(): bool
    {
        return $this->status === AppointmentStatus::COMPLETED;
    }
```
(`AppointmentStatus` is already imported in this model; `status` is cast to the enum.)

- [ ] **Step 4: Controller** — `app/Http/Controllers/Customer/BookingController.php`

```php
<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The logged-in customer's bookings across every salon. No tenant is bound,
 * so the tenant global scope is inert and these queries reach all orgs — which
 * is exactly why every query is filtered by the account's own customer rows.
 */
class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ids = $this->ownedCustomerIds($request);

        $appointments = Appointment::whereIn('customer_id', $ids)
            ->with(['organization', 'service', 'staff', 'branch', 'review', 'payments'])
            ->get();

        $today = now()->toDateString();

        [$upcoming, $past] = $appointments->partition(
            fn (Appointment $a) => $a->booking_date->toDateString() >= $today && $a->isChangeable()
        );

        $upcoming = $upcoming->sortBy([['booking_date', 'asc'], ['start_time', 'asc']])
            ->map(fn (Appointment $a) => $this->present($a))->values();
        $past = $past->sortByDesc(fn (Appointment $a) => $a->booking_date->toDateString().$a->start_time)
            ->map(fn (Appointment $a) => $this->present($a))->values();

        return response()->json(['data' => ['upcoming' => $upcoming, 'past' => $past]]);
    }

    /** Ids of the customer rows this account owns — the isolation boundary. */
    protected function ownedCustomerIds(Request $request): \Illuminate\Support\Collection
    {
        return Customer::where('customer_account_id', $request->user()->id)->pluck('id');
    }

    /** @return array<string, mixed> */
    protected function present(Appointment $a): array
    {
        $today = now()->toDateString();
        $isUpcoming = $a->booking_date->toDateString() >= $today && $a->isChangeable();

        return [
            'id' => $a->id,
            'salon' => ['id' => $a->organization?->id, 'name' => $a->organization?->name, 'slug' => $a->organization?->slug],
            'service' => $a->service?->name,
            'staff' => $a->staff?->name,
            'branch' => $a->branch?->name,
            'booking_date' => $a->booking_date->format('Y-m-d'),
            'start_time' => substr($a->start_time, 0, 5),
            'end_time' => substr($a->end_time, 0, 5),
            'status' => $a->status->value,
            'price' => number_format((float) $a->price, 2, '.', ''),
            'amount_paid' => $a->amountPaid(),
            'balance_due' => $a->balanceDue(),
            'can_manage' => $isUpcoming,
            'can_review' => $a->isCompleted() && $a->review === null,
            'review' => $a->review ? [
                'id' => $a->review->id,
                'rating' => $a->review->rating,
                'comment' => $a->review->comment,
                'status' => $a->review->status,
                'created_at' => $a->review->created_at,
            ] : null,
        ];
    }
}
```

- [ ] **Step 5: Route** — `routes/api.php`

Add the import:
```php
use App\Http\Controllers\Customer\BookingController as CustomerBookingController;
```
Inside the existing `customer` → `auth:customer` group (from Task 2), add:
```php
        Route::get('bookings', [CustomerBookingController::class, 'index']);
```

- [ ] **Step 6: Run the tests — verify pass**

Run: `php artisan test tests/Feature/Customer/BookingsTest.php`
Expected: PASS.

- [ ] **Step 7: Full suite**

Run: `php artisan test`
Expected: ALL green, including `test_staff_token_rejected_on_customer_bookings_route` (guard separation on a customer data route).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Customer/BookingController.php app/Models/Appointment.php routes/api.php tests/Feature/Customer/BookingsTest.php
git commit -m "feat: customer dashboard bookings list (cross-salon, isolated)"
```

---

## Task 5: Booking actions — cancel / slots / reschedule / review

**Files:**
- Modify: `app/Http/Controllers/Customer/BookingController.php` (add methods), `routes/api.php`
- Test: `tests/Feature/Customer/BookingsTest.php` (append)

**Interfaces:**
- Consumes: dashboard controller (Task 4), `SlotGenerator`, `AppointmentScheduler`, `BookingNotifier`, `StoreReviewRequest`, `CurrentTenant`.
- Produces: `POST /bookings/{appointment}/cancel`, `GET /bookings/{appointment}/slots?date=`, `POST /bookings/{appointment}/reschedule`, `POST /bookings/{appointment}/review`. Every action resolves the appointment scoped to the account's owned customer rows (foreign/nonexistent → 404), then binds the appointment's org before reusing the booking engine.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/Customer/BookingsTest.php`

Add `use App\Enums\AppointmentStatus;` and `use App\Models\Review;` (already imported) at the top. Append these methods. They rely on real staff working hours (Mon–Fri 09:00–17:00) so slots exist on a weekday; `2026-08-10` is a Monday.

```php
    public function test_cancel_owned_upcoming_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2999-01-01', 'status' => 'confirmed']);

        $res = $this->withToken($this->tokenFor($account))->postJson("/api/customer/bookings/{$appt->id}/cancel");

        $res->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', $appt->fresh()->status->value);
    }

    public function test_cannot_cancel_completed_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);

        $this->withToken($this->tokenFor($account))->postJson("/api/customer/bookings/{$appt->id}/cancel")
            ->assertStatus(422);
    }

    public function test_cannot_cancel_foreign_booking(): void
    {
        $org = $this->makeOrg('acme');
        $mine = $this->account('jane@x.test');
        $theirs = $this->account('bob@x.test');
        $appt = $this->makeBooking($org, $theirs, ['date' => '2999-01-01', 'status' => 'confirmed']);

        $this->withToken($this->tokenFor($mine))->postJson("/api/customer/bookings/{$appt->id}/cancel")
            ->assertNotFound();
    }

    public function test_slots_and_reschedule_owned_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        // Monday, in the future relative to nothing-scheduled; staff works Mon–Fri.
        $appt = $this->makeBooking($org, $account, ['date' => '2026-08-10', 'start_time' => '10:00:00', 'status' => 'confirmed']);
        $token = $this->tokenFor($account);

        $slots = $this->withToken($token)->getJson("/api/customer/bookings/{$appt->id}/slots?date=2026-08-10");
        $slots->assertOk()->assertJsonStructure(['data' => ['date', 'slots']]);
        $open = $slots->json('data.slots');
        $this->assertNotEmpty($open);

        $target = $open[count($open) - 1];
        $res = $this->withToken($token)->postJson("/api/customer/bookings/{$appt->id}/reschedule", ['date' => '2026-08-10', 'start_time' => $target]);
        $res->assertOk()->assertJsonPath('data.start_time', $target);
        $this->assertSame($target, substr($appt->fresh()->start_time, 0, 5));
    }

    public function test_reschedule_foreign_booking_is_404(): void
    {
        $org = $this->makeOrg('acme');
        $theirs = $this->account('bob@x.test');
        $mine = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $theirs, ['date' => '2026-08-10', 'status' => 'confirmed']);

        $this->withToken($this->tokenFor($mine))->postJson("/api/customer/bookings/{$appt->id}/reschedule", ['date' => '2026-08-10', 'start_time' => '11:00'])
            ->assertNotFound();
    }

    public function test_review_completed_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);

        $res = $this->withToken($this->tokenFor($account))->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 5, 'comment' => 'Loved it']);

        $res->assertCreated();
        $this->assertDatabaseHas('reviews', ['appointment_id' => $appt->id, 'rating' => 5, 'organization_id' => $org->id]);
    }

    public function test_cannot_review_non_completed_booking(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2999-01-01', 'status' => 'confirmed']);

        $this->withToken($this->tokenFor($account))->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 5])
            ->assertStatus(422);
    }

    public function test_cannot_review_twice(): void
    {
        $org = $this->makeOrg('acme');
        $account = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $account, ['date' => '2000-01-01', 'status' => 'completed']);
        $token = $this->tokenFor($account);
        $this->withToken($token)->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 5])->assertCreated();

        $this->withToken($token)->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 4])
            ->assertStatus(409);
    }

    public function test_cannot_review_foreign_booking(): void
    {
        $org = $this->makeOrg('acme');
        $theirs = $this->account('bob@x.test');
        $mine = $this->account('jane@x.test');
        $appt = $this->makeBooking($org, $theirs, ['date' => '2000-01-01', 'status' => 'completed']);

        $this->withToken($this->tokenFor($mine))->postJson("/api/customer/bookings/{$appt->id}/review", ['rating' => 5])
            ->assertNotFound();
    }
```

- [ ] **Step 2: Run — verify failure**

Run: `php artisan test tests/Feature/Customer/BookingsTest.php`
Expected: FAIL — 404/405 on the new action routes.

- [ ] **Step 3: Add the action methods** to `app/Http/Controllers/Customer/BookingController.php`

Add imports:
```php
use App\Http\Requests\Review\StoreReviewRequest;
use App\Models\Review;
use App\Services\AppointmentScheduler;
use App\Services\BookingNotifier;
use App\Services\SlotGenerator;
use App\Tenancy\CurrentTenant;
```
Add methods to the class:
```php
    public function cancel(Request $request, string $appointment, BookingNotifier $notifier): JsonResponse
    {
        $booking = $this->ownedBooking($request, $appointment);

        if (! $booking->isChangeable()) {
            return response()->json(['message' => 'This booking can no longer be changed.'], 422);
        }

        $this->bindTenant($booking);
        $booking->update(['status' => \App\Enums\AppointmentStatus::CANCELLED->value]);
        $fresh = $booking->fresh()->load(['organization', 'service', 'staff', 'branch', 'review', 'payments', 'customer']);
        $notifier->sendForCancellation($fresh);

        return response()->json(['data' => $this->present($fresh)]);
    }

    public function slots(Request $request, string $appointment, SlotGenerator $slotGenerator): JsonResponse
    {
        $booking = $this->ownedBooking($request, $appointment);

        if (! $booking->isChangeable()) {
            return response()->json(['message' => 'This booking can no longer be changed.'], 422);
        }

        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $this->bindTenant($booking);
        $booking->loadMissing(['service', 'staff', 'branch']);

        return response()->json(['data' => [
            'date' => $data['date'],
            'slots' => $slotGenerator->generate($booking->service, $booking->staff, $data['date'], $booking->branch, $booking->id),
        ]]);
    }

    public function reschedule(Request $request, string $appointment, SlotGenerator $slotGenerator, AppointmentScheduler $scheduler, BookingNotifier $notifier): JsonResponse
    {
        $booking = $this->ownedBooking($request, $appointment);

        if (! $booking->isChangeable()) {
            return response()->json(['message' => 'This booking can no longer be changed.'], 422);
        }

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
        ]);

        $this->bindTenant($booking);
        $booking->loadMissing(['service', 'staff', 'branch']);

        $startTime = $scheduler->normalizeTime($data['start_time']);
        $endTime = $scheduler->deriveEndTime($data['start_time'], $booking->service->duration);

        $open = $slotGenerator->generate($booking->service, $booking->staff, $data['date'], $booking->branch, $booking->id);
        if (! in_array(substr($startTime, 0, 5), $open, true)) {
            return response()->json(['message' => 'Sorry, that time slot is no longer available.'], 422);
        }

        $booking->update(['booking_date' => $data['date'], 'start_time' => $startTime, 'end_time' => $endTime]);
        $fresh = $booking->fresh()->load(['organization', 'service', 'staff', 'branch', 'review', 'payments', 'customer']);
        $notifier->sendForReschedule($fresh);

        return response()->json(['data' => $this->present($fresh)]);
    }

    public function review(StoreReviewRequest $request, string $appointment): JsonResponse
    {
        $booking = $this->ownedBooking($request, $appointment);

        abort_unless($booking->isCompleted(), 422, 'You can only review a completed appointment.');
        abort_if(Review::where('appointment_id', $booking->id)->exists(), 409, 'This appointment has already been reviewed.');

        // Bind the org so the review's organization_id is auto-filled by the
        // BelongsToOrganization creating hook.
        $this->bindTenant($booking);
        $booking->loadMissing('customer');

        $review = Review::create([
            'appointment_id' => $booking->id,
            'staff_id' => $booking->staff_id,
            'rating' => $request->integer('rating'),
            'comment' => $request->input('comment'),
            'reviewer_name' => $booking->customer?->name ?? 'Guest',
        ]);

        return response()->json(['data' => [
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'status' => $review->status,
        ]], 201);
    }

    /** Resolve an appointment scoped to the account's own rows — foreign/unknown → 404. */
    protected function ownedBooking(Request $request, string $appointment): Appointment
    {
        return Appointment::whereIn('customer_id', $this->ownedCustomerIds($request))->findOrFail($appointment);
    }

    /** Bind the booking's organization so the reused booking engine is org-scoped. */
    protected function bindTenant(Appointment $booking): void
    {
        $booking->loadMissing('organization');
        app(CurrentTenant::class)->set($booking->organization);
    }
```
Note: `Appointment` is already imported (Task 4). The `review` method uses `StoreReviewRequest` whose `authorize()` returns true and whose rules validate `rating` 1–5 + nullable `comment` — reused as-is.

- [ ] **Step 4: Routes** — inside the `auth:customer` group in `routes/api.php`, add:
```php
        Route::post('bookings/{appointment}/cancel', [CustomerBookingController::class, 'cancel']);
        Route::get('bookings/{appointment}/slots', [CustomerBookingController::class, 'slots']);
        Route::post('bookings/{appointment}/reschedule', [CustomerBookingController::class, 'reschedule']);
        Route::post('bookings/{appointment}/review', [CustomerBookingController::class, 'review']);
```

- [ ] **Step 5: Run the tests — verify pass**

Run: `php artisan test tests/Feature/Customer/BookingsTest.php`
Expected: PASS (all, including the action tests).

- [ ] **Step 6: Full suite**

Run: `php artisan test`
Expected: ALL green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Customer/BookingController.php routes/api.php tests/Feature/Customer/BookingsTest.php
git commit -m "feat: customer cancel / reschedule / slots / review actions"
```

---

## Task 6: Frontend — customer auth (client + store + login + routing)

**Files:**
- Create: `frontend/src/lib/customerApi.js`, `frontend/src/stores/customerAuth.js`, `frontend/src/layouts/CustomerLayout.vue`, `frontend/src/views/CustomerLoginView.vue`
- Modify: `frontend/src/router/index.js`
- Test: build only (`npm run build`)

**Interfaces:**
- Consumes: `POST /customer/auth/request-code`, `POST /customer/auth/verify-code`, `GET /customer/auth/me`, `POST /customer/auth/logout`.
- Produces: `useCustomerAuthStore` (token/account, `requestCode`, `verifyCode`, `fetchMe`, `logout`, `isAuthenticated`), routes `/account/login` (`CustomerLoginView`) and the `requiresCustomerAuth` guard branch (dashboard route added in Task 7).

- [ ] **Step 1: API client** — `frontend/src/lib/customerApi.js`
```js
import axios from 'axios'

// Separate token + client from staff auth so the two sessions never collide.
export const CUSTOMER_TOKEN_KEY = 'salonhub_customer_token'

const customerApi = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { Accept: 'application/json' },
})

customerApi.interceptors.request.use((config) => {
  const token = localStorage.getItem(CUSTOMER_TOKEN_KEY)
  if (token) {
    config.headers = config.headers || {}
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// On an expired/invalid customer token, clear it and bounce to the customer
// login (never the staff /login).
customerApi.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      localStorage.removeItem(CUSTOMER_TOKEN_KEY)
      if (window.location.pathname !== '/account/login') {
        window.location.assign('/account/login')
      }
    }
    return Promise.reject(error)
  },
)

export default customerApi
```

- [ ] **Step 2: Store** — `frontend/src/stores/customerAuth.js`
```js
import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import customerApi, { CUSTOMER_TOKEN_KEY } from '@/lib/customerApi'

export const useCustomerAuthStore = defineStore('customerAuth', () => {
  const token = ref(localStorage.getItem(CUSTOMER_TOKEN_KEY) || null)
  const account = ref(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value)

  function setToken(value) {
    token.value = value
    localStorage.setItem(CUSTOMER_TOKEN_KEY, value)
  }

  function clear() {
    token.value = null
    account.value = null
    localStorage.removeItem(CUSTOMER_TOKEN_KEY)
  }

  async function requestCode(email) {
    loading.value = true
    try {
      await customerApi.post('/customer/auth/request-code', { email })
    } finally {
      loading.value = false
    }
  }

  async function verifyCode(email, code) {
    loading.value = true
    try {
      const { data } = await customerApi.post('/customer/auth/verify-code', { email, code })
      setToken(data.token)
      account.value = data.account
      return data
    } finally {
      loading.value = false
    }
  }

  async function fetchMe() {
    const { data } = await customerApi.get('/customer/auth/me')
    account.value = data.account
    return data
  }

  async function logout() {
    try {
      await customerApi.post('/customer/auth/logout')
    } catch {
      // Clear regardless.
    } finally {
      clear()
    }
  }

  return { token, account, loading, isAuthenticated, requestCode, verifyCode, fetchMe, logout }
})
```

- [ ] **Step 3: Layout** — `frontend/src/layouts/CustomerLayout.vue`
```vue
<script setup>
import { useRouter, RouterView } from 'vue-router'
import { useCustomerAuthStore } from '@/stores/customerAuth'

const router = useRouter()
const auth = useCustomerAuthStore()

async function signOut() {
  await auth.logout()
  router.push('/account/login')
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-4">
        <span class="text-lg font-semibold text-slate-900">My bookings</span>
        <button
          v-if="auth.isAuthenticated"
          class="text-sm text-slate-500 hover:text-slate-900"
          @click="signOut"
        >Log out</button>
      </div>
    </header>
    <main class="mx-auto max-w-4xl px-4 py-8">
      <RouterView />
    </main>
  </div>
</template>
```

- [ ] **Step 4: Login view** — `frontend/src/views/CustomerLoginView.vue`
```vue
<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useCustomerAuthStore } from '@/stores/customerAuth'

const router = useRouter()
const auth = useCustomerAuthStore()

const step = ref('email') // 'email' | 'code'
const email = ref('')
const code = ref('')
const error = ref('')
const notice = ref('')

async function sendCode() {
  error.value = ''
  notice.value = ''
  try {
    await auth.requestCode(email.value)
    step.value = 'code'
    notice.value = 'Check your email for the 6-digit code.'
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not send the code. Try again.'
  }
}

async function submitCode() {
  error.value = ''
  try {
    await auth.verifyCode(email.value, code.value)
    router.push('/account')
  } catch (e) {
    error.value = e.response?.data?.message || 'Invalid or expired code.'
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-50 px-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
      <h1 class="text-lg font-semibold text-slate-900">Sign in to your bookings</h1>
      <p class="mt-1 text-sm text-slate-500">No password. We email you a code.</p>

      <p v-if="notice" class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ notice }}</p>
      <p v-if="error" class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

      <form v-if="step === 'email'" class="mt-5 space-y-4" @submit.prevent="sendCode">
        <div>
          <label class="block text-sm font-medium text-slate-700">Email</label>
          <input v-model="email" type="email" required autocomplete="email"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" />
        </div>
        <button type="submit" :disabled="auth.loading"
          class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
          {{ auth.loading ? 'Sending…' : 'Send code' }}
        </button>
      </form>

      <form v-else class="mt-5 space-y-4" @submit.prevent="submitCode">
        <div>
          <label class="block text-sm font-medium text-slate-700">6-digit code</label>
          <input v-model="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"
            class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-center text-lg tracking-widest focus:border-indigo-500 focus:outline-none" />
        </div>
        <button type="submit" :disabled="auth.loading"
          class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
          {{ auth.loading ? 'Verifying…' : 'Sign in' }}
        </button>
        <button type="button" class="w-full text-center text-sm text-slate-500 hover:text-slate-900" @click="sendCode">
          Resend code
        </button>
      </form>
    </div>
  </div>
</template>
```

- [ ] **Step 5: Router** — `frontend/src/router/index.js`

Add the customer store import at the top (next to `useAuthStore`):
```js
import { useCustomerAuthStore } from '@/stores/customerAuth'
```
Add these two routes to the `routes` array (top level, siblings of `/login`). The dashboard route's component is added in Task 7 — for now register login only and a placeholder-free dashboard entry that Task 7 fills:
```js
    {
      path: '/account/login',
      name: 'customer-login',
      component: () => import('@/views/CustomerLoginView.vue'),
    },
```
In `router.beforeEach`, add before the final `return true`:
```js
  if (to.meta.requiresCustomerAuth) {
    const customerAuth = useCustomerAuthStore()
    if (!customerAuth.isAuthenticated) {
      return '/account/login'
    }
  }
  if (to.path === '/account/login' && useCustomerAuthStore().isAuthenticated) {
    return '/account'
  }
```

- [ ] **Step 6: Build**

Run: `cd frontend && npm run build`
Expected: clean build; `CustomerLoginView` chunk emitted. (`/account` route lands in Task 7; navigating there now is out of scope for this task.)

- [ ] **Step 7: Commit**
```bash
git add frontend/src/lib/customerApi.js frontend/src/stores/customerAuth.js frontend/src/layouts/CustomerLayout.vue frontend/src/views/CustomerLoginView.vue frontend/src/router/index.js
git commit -m "feat: customer passwordless login (client, store, view, routing)"
```

---

## Task 7: Frontend — dashboard view + actions + entry link

**Files:**
- Create: `frontend/src/views/CustomerDashboardView.vue`
- Modify: `frontend/src/router/index.js` (dashboard route under `CustomerLayout`), `frontend/src/views/SalonSiteView.vue` (entry link)
- Test: build only

**Interfaces:**
- Consumes: `GET /customer/bookings`, `GET /customer/bookings/{id}/slots`, `POST /customer/bookings/{id}/reschedule`, `POST /customer/bookings/{id}/cancel`, `POST /customer/bookings/{id}/review`; `CustomerLayout`, `customerApi`.
- Produces: `/account` route (dashboard), a login entry link on the salon site.

- [ ] **Step 1: Dashboard view** — `frontend/src/views/CustomerDashboardView.vue`
```vue
<script setup>
import { ref, onMounted } from 'vue'
import customerApi from '@/lib/customerApi'

const loading = ref(true)
const error = ref('')
const upcoming = ref([])
const past = ref([])

// Reschedule modal state.
const rescheduling = ref(null) // booking object
const rDate = ref('')
const rSlots = ref([])
const rSlot = ref('')
const rLoadingSlots = ref(false)
const rError = ref('')

// Review modal state.
const reviewing = ref(null)
const vRating = ref(5)
const vComment = ref('')
const vError = ref('')

function money(v) {
  return `$${Number(v).toFixed(2)}`
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await customerApi.get('/customer/bookings')
    upcoming.value = data.data.upcoming
    past.value = data.data.past
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load your bookings.'
  } finally {
    loading.value = false
  }
}

async function cancel(booking) {
  if (!window.confirm('Cancel this booking?')) return
  try {
    await customerApi.post(`/customer/bookings/${booking.id}/cancel`)
    await load()
  } catch (e) {
    window.alert(e.response?.data?.message || 'Could not cancel.')
  }
}

function openReschedule(booking) {
  rescheduling.value = booking
  rDate.value = booking.booking_date
  rSlots.value = []
  rSlot.value = ''
  rError.value = ''
  loadSlots()
}

async function loadSlots() {
  if (!rDate.value) return
  rLoadingSlots.value = true
  rError.value = ''
  rSlot.value = ''
  try {
    const { data } = await customerApi.get(`/customer/bookings/${rescheduling.value.id}/slots`, { params: { date: rDate.value } })
    rSlots.value = data.data.slots
  } catch (e) {
    rError.value = e.response?.data?.message || 'Could not load slots.'
  } finally {
    rLoadingSlots.value = false
  }
}

async function submitReschedule() {
  rError.value = ''
  try {
    await customerApi.post(`/customer/bookings/${rescheduling.value.id}/reschedule`, { date: rDate.value, start_time: rSlot.value })
    rescheduling.value = null
    await load()
  } catch (e) {
    rError.value = e.response?.data?.message || 'Could not reschedule.'
  }
}

function openReview(booking) {
  reviewing.value = booking
  vRating.value = 5
  vComment.value = ''
  vError.value = ''
}

async function submitReview() {
  vError.value = ''
  try {
    await customerApi.post(`/customer/bookings/${reviewing.value.id}/review`, { rating: vRating.value, comment: vComment.value || null })
    reviewing.value = null
    await load()
  } catch (e) {
    vError.value = e.response?.data?.message || 'Could not submit review.'
  }
}

onMounted(load)
</script>

<template>
  <div>
    <p v-if="loading" class="text-sm text-slate-500">Loading…</p>
    <p v-else-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ error }}</p>

    <template v-else>
      <section>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Upcoming</h2>
        <p v-if="!upcoming.length" class="mt-2 text-sm text-slate-500">No upcoming bookings.</p>
        <ul class="mt-3 space-y-3">
          <li v-for="b in upcoming" :key="b.id" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="font-medium text-slate-900">{{ b.service }} <span class="text-slate-400">·</span> {{ b.salon.name }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ b.booking_date }} at {{ b.start_time }} · {{ b.staff }} · {{ b.branch }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ money(b.price) }} · paid {{ money(b.amount_paid) }} · due {{ money(b.balance_due) }}</p>
              </div>
              <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ b.status }}</span>
            </div>
            <div v-if="b.can_manage" class="mt-3 flex gap-2">
              <button class="rounded-lg bg-slate-100 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-200" @click="openReschedule(b)">Reschedule</button>
              <button class="rounded-lg bg-rose-50 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-100" @click="cancel(b)">Cancel</button>
            </div>
          </li>
        </ul>
      </section>

      <section class="mt-8">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Past</h2>
        <p v-if="!past.length" class="mt-2 text-sm text-slate-500">No past bookings.</p>
        <ul class="mt-3 space-y-3">
          <li v-for="b in past" :key="b.id" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="font-medium text-slate-900">{{ b.service }} <span class="text-slate-400">·</span> {{ b.salon.name }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ b.booking_date }} at {{ b.start_time }} · {{ b.staff }}</p>
              </div>
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ b.status }}</span>
            </div>
            <div v-if="b.review" class="mt-2 text-sm text-amber-600">★ {{ b.review.rating }} <span class="text-slate-400">{{ b.review.comment }}</span></div>
            <button v-else-if="b.can_review" class="mt-3 rounded-lg bg-amber-50 px-3 py-1.5 text-sm text-amber-700 hover:bg-amber-100" @click="openReview(b)">Leave review</button>
          </li>
        </ul>
      </section>
    </template>

    <!-- Reschedule modal -->
    <div v-if="rescheduling" class="fixed inset-0 z-10 flex items-center justify-center bg-black/30 px-4" @click.self="rescheduling = null">
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
        <h3 class="text-base font-semibold text-slate-900">Reschedule</h3>
        <label class="mt-4 block text-sm font-medium text-slate-700">Date</label>
        <input v-model="rDate" type="date" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" @change="loadSlots" />
        <p v-if="rError" class="mt-3 text-sm text-rose-700">{{ rError }}</p>
        <p v-if="rLoadingSlots" class="mt-3 text-sm text-slate-500">Loading slots…</p>
        <div v-else class="mt-3 flex max-h-40 flex-wrap gap-2 overflow-y-auto">
          <button v-for="s in rSlots" :key="s" type="button"
            class="rounded-lg border px-2.5 py-1 text-sm"
            :class="rSlot === s ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-700'"
            @click="rSlot = s">{{ s }}</button>
          <p v-if="!rSlots.length" class="text-sm text-slate-500">No open slots.</p>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-lg px-3 py-1.5 text-sm text-slate-500" @click="rescheduling = null">Close</button>
          <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white disabled:opacity-50" :disabled="!rSlot" @click="submitReschedule">Confirm</button>
        </div>
      </div>
    </div>

    <!-- Review modal -->
    <div v-if="reviewing" class="fixed inset-0 z-10 flex items-center justify-center bg-black/30 px-4" @click.self="reviewing = null">
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-lg">
        <h3 class="text-base font-semibold text-slate-900">Leave a review</h3>
        <label class="mt-4 block text-sm font-medium text-slate-700">Rating</label>
        <select v-model.number="vRating" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option v-for="n in 5" :key="n" :value="n">{{ n }} star{{ n > 1 ? 's' : '' }}</option>
        </select>
        <label class="mt-4 block text-sm font-medium text-slate-700">Comment</label>
        <textarea v-model="vComment" rows="3" maxlength="1000" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
        <p v-if="vError" class="mt-3 text-sm text-rose-700">{{ vError }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button class="rounded-lg px-3 py-1.5 text-sm text-slate-500" @click="reviewing = null">Close</button>
          <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-sm text-white" @click="submitReview">Submit</button>
        </div>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Dashboard route** — `frontend/src/router/index.js`

Add a route (top level) wrapping the dashboard in `CustomerLayout`. Import the layout at the top:
```js
import CustomerLayout from '../layouts/CustomerLayout.vue'
```
Add to `routes`:
```js
    {
      path: '/account',
      component: CustomerLayout,
      children: [
        {
          path: '',
          name: 'customer-dashboard',
          component: () => import('@/views/CustomerDashboardView.vue'),
          meta: { requiresCustomerAuth: true },
        },
      ],
    },
```

- [ ] **Step 3: Entry link** — `frontend/src/views/SalonSiteView.vue`

Open the file, and in the site header/nav area (near the salon name or the primary "Book" action), add a link to the customer login. Insert this element in a sensible spot in the template (a top-right nav/header region):
```vue
<router-link to="/account/login" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">
  Manage my bookings
</router-link>
```
If the file has no obvious header region, place it directly above the services/booking section so it is visible on load. Keep styling consistent with nearby elements.

- [ ] **Step 4: Build**

Run: `cd frontend && npm run build`
Expected: clean build; `CustomerDashboardView` chunk emitted.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/views/CustomerDashboardView.vue frontend/src/router/index.js frontend/src/views/SalonSiteView.vue
git commit -m "feat: customer dashboard view (bookings, reschedule, cancel, review)"
```

---

## Post-plan: hardening test (after Task 7)

Add ONE more feature test asserting a customer token cannot read another account's single booking action even by guessing ids — already covered by the `foreign → 404` tests in Task 5. No extra work; the final whole-branch review confirms the isolation guarantees hold across the payload.

## Notes for the executor

- BASE for each task's review package = the commit recorded BEFORE dispatching that task's implementer (never `HEAD~1`).
- The guard-separation test is the security gate for Global Constraint "Guard separation" — it MUST pass both directions before Task 2 is marked complete (the staff-token→`/api/customer/bookings` assertion goes green in Task 4).
- Deferred/none: profile edit, password login, phone claiming — all explicitly out of scope (spec § Out of scope).
