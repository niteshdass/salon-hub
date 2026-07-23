<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HelloController;
use App\Http\Controllers\Public\BookingController;
use App\Http\Controllers\ServiceCategoryController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StaffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/hello', [HelloController::class, 'index']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Tenant-scoped API: auth:sanctum authenticates, then `tenant` binds the
// current organization so every query is auto-filtered by organization_id.
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('appointments', AppointmentController::class);

    Route::apiResource('branches', BranchController::class);
    Route::apiResource('categories', ServiceCategoryController::class)
        ->parameters(['categories' => 'category']);
    Route::apiResource('services', ServiceController::class);
    Route::apiResource('staff', StaffController::class)
        ->parameters(['staff' => 'staff']);
});

// Public (no-auth) customer booking site. `public.tenant` resolves the
// organization from the {org} slug (or host header) and binds it, so every
// query — including implicit {service} binding — is tenant-scoped.
Route::prefix('public/{org}')->middleware('public.tenant')->group(function () {
    Route::get('/', [BookingController::class, 'organization']);
    Route::get('services', [BookingController::class, 'services']);
    Route::get('services/{service}/staff', [BookingController::class, 'staffForService']);
    Route::get('slots', [BookingController::class, 'slots']);
    Route::post('book', [BookingController::class, 'book']);

    // Token-based self-service management of an existing booking (no auth).
    Route::get('manage/{token}', [BookingController::class, 'manage']);
    Route::post('manage/{token}/reschedule', [BookingController::class, 'reschedule']);
    Route::post('manage/{token}/cancel', [BookingController::class, 'cancel']);
});
