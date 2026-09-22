<?php

use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth', 'verified', EnsureSuperAdmin::class])
    ->name('admin.')
    ->group(function () {
        Route::livewire('/', 'pages::admin.overview')->name('dashboard');
        Route::livewire('landlords', 'pages::admin.landlords')->name('landlords');
    });
