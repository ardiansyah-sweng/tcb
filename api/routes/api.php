<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

// Prefix: /api/v1 (see bootstrap/app.php).

Route::get('/health', HealthController::class)->name('health');
