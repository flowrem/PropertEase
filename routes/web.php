<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

        Route::livewire('billing', 'pages::billing')->name('billing');
        Route::livewire('maintenance', 'pages::maintenance')->name('maintenance');
        Route::livewire('complaints', 'pages::complaints')->name('complaints');
        Route::livewire('announcements', 'pages::announcements')->name('announcements');

        Route::middleware(EnsureTeamMembership::class.':member')->group(function () {
            Route::livewire('setup', 'pages::landlord.setup')->name('setup');
            Route::livewire('properties', 'pages::landlord.properties')->name('properties');
            Route::livewire('tenants', 'pages::landlord.tenants')->name('tenants');
            Route::livewire('invoices', 'pages::landlord.invoices')->name('invoices');
            Route::livewire('inbox', 'pages::landlord.inbox')->name('inbox');
        });
    });

require __DIR__.'/settings.php';
