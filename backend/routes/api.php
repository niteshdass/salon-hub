<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HelloController;
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
    Route::get('customers', [CustomerController::class, 'index']);
});
