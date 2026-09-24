<?php

use App\Http\Controllers\ForcedPasswordChangeController;
use App\Http\Controllers\PublicListingController;
use App\Http\Controllers\ReservationFileController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('find-a-place', [PublicListingController::class, 'index'])->name('listings.index');
Route::get('find-a-place/{listing}', [PublicListingController::class, 'show'])
    ->whereNumber('listing')
    ->name('listings.show');
Route::livewire('find-a-place/{listing}/reserve', 'pages::reserve')
    ->whereNumber('listing')
    ->name('listings.reserve');

Route::middleware('auth')->group(function () {
    Route::get('change-password', [ForcedPasswordChangeController::class, 'show'])->name('password.change');
    Route::post('change-password', [ForcedPasswordChangeController::class, 'store'])->name('password.change.store');
});

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
            Route::livewire('requests/maintenance', 'pages::landlord.maintenance')->name('landlord.maintenance');
            Route::livewire('requests/complaints', 'pages::landlord.complaints')->name('landlord.complaints');
            Route::get('inbox', fn () => redirect()->route('landlord.maintenance'))->name('inbox');
            Route::livewire('listings', 'pages::landlord.listings')->name('listings');
            Route::livewire('payment-settings', 'pages::landlord.payment-settings')->name('payment-settings');
            Route::livewire('reservations', 'pages::landlord.reservations')->name('reservations');
            Route::get('reservations/{reservation}/files/{kind}', ReservationFileController::class)
                ->whereNumber('reservation')
                ->whereIn('kind', ['id', 'proof'])
                ->name('reservations.files');
        });
    });

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
