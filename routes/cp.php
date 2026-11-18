<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Bloodhound\Http\Controllers\HealthActionController;

// The cp group already applies auth + can:accessCp; these write to the engine.
Route::middleware('can:utility:bloodhound')->prefix('bloodhound')->group(function () {
    Route::post('repair', [HealthActionController::class, 'repair']);
    Route::post('rebuild', [HealthActionController::class, 'rebuild']);
});
