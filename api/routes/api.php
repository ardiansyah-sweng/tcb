<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ObjectiveController;
use Illuminate\Support\Facades\Route;

// Prefix: /api/v1 (see bootstrap/app.php).
// Public: health and login only. There is no registration endpoint; accounts
// are created with `php artisan tcb:create-user`.

Route::get('/health', HealthController::class)->name('health');
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', MeController::class)->name('me');

    Route::apiResource('objectives', ObjectiveController::class)
        ->only(['index', 'store', 'show', 'update'])
        ->whereNumber('objective');
});
