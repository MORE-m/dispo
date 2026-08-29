<?php

use App\Http\Controllers\AdministrationAccessController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('admin', AdministrationAccessController::class)->name('admin.access');
});

require __DIR__.'/settings.php';
