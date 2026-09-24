<?php

use App\Http\Controllers\LandlordIdController;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth', 'verified', EnsureSuperAdmin::class])
    ->name('admin.')
    ->group(function () {
        Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
        Route::livewire('landlords', 'pages::admin.landlords')->name('landlords');
        Route::get('landlords/{team}/id', LandlordIdController::class)->whereNumber('team')->name('landlords.id');
        Route::livewire('listings', 'pages::admin.listings')->name('listings');
    });
