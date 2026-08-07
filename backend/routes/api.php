<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BranchClosureController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationSettingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentSettingController;
use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\Public\DiscoveryController;
use App\Http\Controllers\Public\PaymentCallbackController;
use App\Http\Controllers\Public\ReviewController as PublicReviewController;
use App\Http\Controllers\Public\SiteController;
use App\Http\Controllers\ReminderSettingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ServiceCategoryController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServicePresetController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffTimeOffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/hello', [HelloController::class, 'index']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'tenant']);

Route::prefix('auth')->group(function () {
    // Creating an organization is expensive (org + owner + domain + branch +
    // settings rows, plus a verification email). Three per minute per IP is
    // far above any human signup rate and well below a spam run.
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:3,1');

    // The per-email lockout lives in AuthController::login; this per-IP cap
    // is the second layer, bounding an attacker spraying many accounts.
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:20,1');

    // Password reset. Throttled hard: both endpoints are unauthenticated
    // and both send or consume a token tied to a real account.
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:6,1');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1');

    // Signed, not authenticated — the link is clicked from an inbox.
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed:relative', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        // Deliberately OUTSIDE `tenant`. Signing out must keep working for a
        // member whose organization was suspended or removed — otherwise the
        // only way to drop a dead session is to clear browser storage. It
        // touches the current access token and nothing tenant-scoped.
        Route::post('logout', [AuthController::class, 'logout']);

        // Also outside `tenant`: it re-sends a verification link to the
        // address on the user row, reads nothing tenant-scoped, and is
        // already throttled.
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1');

        // `tenant` here is the SESSION-layer status check, and it is the
        // reason this route is declared separately. This endpoint is what the
        // SPA calls to turn a stored token into a session. Without the
        // middleware it answered 200 with the full user and organization for
        // a suspended or inactive salon, so the owner was admitted into the
        // dashboard shell and then met a 403 on every panel inside it, with
        // no statement anywhere of what was actually wrong. Enforcing the
        // same rule login enforces at the door means a dead session is
        // refused once, with a reason the SPA can show.
        Route::get('me', [AuthController::class, 'me'])->middleware('tenant');
    });
});

// Tenant-scoped API: auth:sanctum authenticates, then `tenant` binds the
// current organization so every query is auto-filtered by organization_id.
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    // First-run setup. Owner-only; the policy check lives in the controller.
    Route::get('onboarding/status', [OnboardingController::class, 'status']);
    Route::post('onboarding/complete', [OnboardingController::class, 'complete']);

    // Starter service menus for the wizard's "What do you offer?" screen.
    // Static config, no tenant data — authenticated only because it has no
    // reason to be public.
    Route::get('service-presets', ServicePresetController::class);

    Route::get('dashboard', DashboardController::class);

    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('appointments', AppointmentController::class);

    // Money against a booking: payments taken and the computed invoice.
    Route::get('appointments/{appointment}/payments', [PaymentController::class, 'index']);
    Route::post('appointments/{appointment}/payments', [PaymentController::class, 'store']);
    Route::delete('appointments/{appointment}/payments/{payment}', [PaymentController::class, 'destroy']);
    Route::post('appointments/{appointment}/payments/{payment}/verify', [PaymentController::class, 'verify']);
    Route::post('appointments/{appointment}/payments/{payment}/refund', [PaymentController::class, 'refund']);
    Route::get('appointments/{appointment}/invoice', InvoiceController::class);

    Route::apiResource('branches', BranchController::class);
    Route::get('branch-closures', [BranchClosureController::class, 'index']);
    Route::post('branch-closures', [BranchClosureController::class, 'store']);
    Route::delete('branch-closures/{branchClosure}', [BranchClosureController::class, 'destroy']);
    Route::apiResource('categories', ServiceCategoryController::class)
        ->parameters(['categories' => 'category']);
    // Declared before the resource: `services/bulk` must not be read as
    // `services/{service}` with an id of "bulk".
    Route::post('services/bulk', [ServiceController::class, 'bulkStore']);
    Route::apiResource('services', ServiceController::class);
    Route::apiResource('staff', StaffController::class)
        ->parameters(['staff' => 'staff']);
    Route::get('staff/{staff}/time-off', [StaffTimeOffController::class, 'index']);
    Route::post('staff/{staff}/time-off', [StaffTimeOffController::class, 'store']);
    Route::delete('staff/{staff}/time-off/{timeOff}', [StaffTimeOffController::class, 'destroy']);

    Route::apiResource('gallery', GalleryController::class)->except('show');

    Route::get('reviews', [ReviewController::class, 'index']);
    Route::patch('reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy']);

    Route::get('reports', ReportController::class);

    Route::get('settings/organization', [OrganizationSettingController::class, 'show']);
    Route::put('settings/organization', [OrganizationSettingController::class, 'update']);
    Route::post('settings/organization/logo', [OrganizationSettingController::class, 'uploadLogo']);
    Route::delete('settings/organization/logo', [OrganizationSettingController::class, 'deleteLogo']);
    Route::post('settings/organization/cover', [OrganizationSettingController::class, 'uploadCover']);
    Route::delete('settings/organization/cover', [OrganizationSettingController::class, 'deleteCover']);

    Route::get('settings/reminders', [ReminderSettingController::class, 'show']);
    Route::put('settings/reminders', [ReminderSettingController::class, 'update']);

    Route::get('settings/payments', [PaymentSettingController::class, 'show']);
    Route::put('settings/payments', [PaymentSettingController::class, 'update']);
});

// Public (no-auth) customer booking site. `public.tenant` resolves the
// organization from the {org} slug (or host header) and binds it, so every
// query — including implicit {service} binding — is tenant-scoped.
Route::prefix('public/{org}')->middleware('public.tenant')->group(function () {
    Route::get('/', [BookingController::class, 'organization']);
    Route::get('site', SiteController::class);
    Route::get('services', [BookingController::class, 'services']);
    Route::get('services/{service}/staff', [BookingController::class, 'staffForService']);
    Route::get('slots', [BookingController::class, 'slots']);
    Route::post('book', [BookingController::class, 'book']);

    // Token-based self-service management of an existing booking (no auth).
    Route::get('manage/{token}', [BookingController::class, 'manage']);
    Route::post('manage/{token}/reschedule', [BookingController::class, 'reschedule']);
    Route::post('manage/{token}/cancel', [BookingController::class, 'cancel']);
    Route::post('manage/{token}/review', [PublicReviewController::class, 'store']);

    // SSLCommerz browser callbacks for an online deposit, keyed by the
    // payment's transaction id. The gateway POSTs the customer here after
    // checkout; each ends in a redirect back to the SPA manage page.
    Route::post('payment/{tran}/callback/success', [PaymentCallbackController::class, 'success']);
    Route::post('payment/{tran}/callback/fail', [PaymentCallbackController::class, 'fail']);
    Route::post('payment/{tran}/callback/cancel', [PaymentCallbackController::class, 'cancel']);

    // Server-to-server IPN: SSLCommerz POSTs here directly, so a captured
    // payment is recorded even if the customer never returns to the browser.
    Route::post('payment/{tran}/ipn', [PaymentCallbackController::class, 'ipn']);
});

// The same booking site, tenant read from the Host header instead of the
// path: <slug>.APP_DOMAIN hits these. It reuses `public.tenant`, whose
// Host-header branch runs when there is no {org} parameter to read.
//
// The prefix is `public-site`, NOT `public`, and that is the whole point of
// the split. While both groups shared `public/`, `api/public/site` was
// ambiguous: it is the host-resolved site endpoint AND it is `api/public/{org}`
// for the salon slugged "site". Whichever group is declared first wins, so one
// of the two was always served the wrong tenant — declaration order can pick
// the victim but cannot remove the ambiguity. Under different literal first
// segments no URI in either group can ever be matched by a route in the other,
// so any route added to either from now on is safe by construction rather than
// by remembering to check.
//
// Shape-for-shape symmetric with the {org} group above, so one frontend view
// calls the same endpoints under either prefix (frontend/src/lib/tenantHost.js,
// publicApiBase). Reserved slugs (Organization::RESERVED_SLUGS) are a second,
// independent guard: the platform's own hostnames cannot be registered.
Route::prefix('public-site')->middleware('public.tenant')->group(function () {
    Route::get('/', [BookingController::class, 'organization']);
    Route::get('site', SiteController::class);
    Route::get('services', [BookingController::class, 'services']);
    // A distinct action from the path-scoped one: this URI has no {org}
    // parameter, and Laravel passes route parameters positionally.
    Route::get('services/{service}/staff', [BookingController::class, 'staffForServiceOnHost']);
    Route::get('slots', [BookingController::class, 'slots']);
    Route::post('book', [BookingController::class, 'book']);
});

// Public marketing-site contact form. No auth, not tenant-scoped. Rate-limited
// against spam: 5 requests per minute per IP.
Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:5,1');

// Platform-wide customer accounts. No `tenant` middleware: the account is a
// global identity, so the tenant scope is intentionally inert and every query
// filters by the account's own customers rows.
Route::prefix('customer')->group(function () {
    Route::post('auth/request-code', [CustomerAuthController::class, 'requestCode'])->middleware('throttle:6,1');
    Route::post('auth/verify-code', [CustomerAuthController::class, 'verifyCode'])->middleware('throttle:10,1');

    Route::middleware('auth:customer')->group(function () {
        Route::get('auth/me', [CustomerAuthController::class, 'me']);
        Route::post('auth/logout', [CustomerAuthController::class, 'logout']);
        Route::get('bookings', [CustomerBookingController::class, 'index']);
        Route::post('bookings/{appointment}/cancel', [CustomerBookingController::class, 'cancel']);
        Route::get('bookings/{appointment}/slots', [CustomerBookingController::class, 'slots']);
        Route::post('bookings/{appointment}/reschedule', [CustomerBookingController::class, 'reschedule']);
        Route::post('bookings/{appointment}/review', [CustomerBookingController::class, 'review']);
    });
});

// Cross-tenant salon discovery for the platform's own search page. Public and
// deliberately NOT tenant-scoped: the point is to look across organizations,
// which is why it lives outside both the `public/{org}` and `public-site`
// groups — `public.tenant` would bind one salon and filter the rest away.
// Throttled because a debounced search box is chatty and an unauthenticated
// cross-tenant endpoint should not be free to scrape.
Route::get('discover/salons', DiscoveryController::class)->middleware('throttle:60,1');
