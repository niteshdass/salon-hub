<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BranchClosureController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\OrganizationSettingController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentSettingController;
use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\Public\PaymentCallbackController;
use App\Http\Controllers\Public\ReviewController as PublicReviewController;
use App\Http\Controllers\Public\SiteController;
use App\Http\Controllers\ReminderSettingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ServiceCategoryController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffTimeOffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/hello', [HelloController::class, 'index']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

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
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1');
    });
});

// Tenant-scoped API: auth:sanctum authenticates, then `tenant` binds the
// current organization so every query is auto-filtered by organization_id.
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
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
