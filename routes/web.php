<?php

use App\Http\Controllers\ConcernPhotoController;
use App\Http\Controllers\ContractPrintController;
use App\Http\Controllers\ForcedPasswordChangeController;
use App\Http\Controllers\LandlordVerificationController;
use App\Http\Controllers\PublicListingController;
use App\Http\Controllers\ReservationFileController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Must be registered before the {current_team} group below: /admin/listings would
// otherwise match {current_team}/listings and fail team membership with a 403.
require __DIR__.'/admin.php';

Route::get('find-a-place', [PublicListingController::class, 'index'])->name('listings.index');
Route::get('find-a-place/{listing}', [PublicListingController::class, 'show'])
    ->whereNumber('listing')
    ->name('listings.show');
Route::livewire('find-a-place/{listing}/reserve', 'pages::reserve')
    ->whereNumber('listing')
    ->name('listings.reserve');

// An applicant's own reservation: the emailed link is signed, otherwise the
// lookup asks for the code and the email used. Before the {current_team}
// group for the same reason as admin.php.
Route::livewire('reservation', 'pages::reservation-lookup')->name('reservations.lookup');
Route::livewire('reservation/{code}', 'pages::reservation-status')
    ->where('code', '[A-Za-z0-9]{4,12}')
    ->name('reservations.status');

Route::middleware('auth')->group(function () {
    Route::get('change-password', [ForcedPasswordChangeController::class, 'show'])->name('password.change');
    Route::post('change-password', [ForcedPasswordChangeController::class, 'store'])->name('password.change.store');

    Route::get('account-review', [LandlordVerificationController::class, 'show'])->name('landlord.verification');
    Route::post('account-review', [LandlordVerificationController::class, 'resubmit'])->name('landlord.verification.resubmit');
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

        Route::livewire('billing', 'pages::billing')->name('billing');
        Route::livewire('maintenance', 'pages::maintenance')->name('maintenance');
        Route::livewire('complaints', 'pages::complaints')->name('complaints');
        Route::livewire('announcements', 'pages::announcements')->name('announcements');
        Route::livewire('unit-checks', 'pages::unit-checks')->name('unit-checks');
        Route::livewire('contract', 'pages::contract')->name('contract');
        Route::get('contracts/{contract}/print', ContractPrintController::class)
            ->whereNumber('contract')
            ->name('contracts.print');
        Route::livewire('notifications', 'pages::notifications')->name('notifications');
        Route::get('reports/{concern}/photo', ConcernPhotoController::class)
            ->whereNumber('concern')
            ->name('concerns.photo');

        Route::middleware(EnsureTeamMembership::class.':member')->group(function () {
            Route::livewire('setup', 'pages::landlord.setup')->name('setup');
            Route::livewire('properties', 'pages::landlord.properties')->name('properties');
            Route::livewire('units/{unit}', 'pages::landlord.unit')
                ->whereNumber('unit')
                ->name('units.show');
            Route::livewire('units/{unit}/edit', 'pages::landlord.unit-edit')
                ->whereNumber('unit')
                ->name('units.edit');
            Route::livewire('units/{unit}/inventory', 'pages::landlord.unit-inventory')
                ->whereNumber('unit')
                ->name('units.inventory');
            Route::livewire('amenities', 'pages::landlord.amenities')->name('amenities');
            Route::livewire('tenants', 'pages::landlord.tenants')->name('tenants');
            Route::livewire('invoices', 'pages::landlord.invoices')->name('invoices');
            Route::livewire('requests/maintenance', 'pages::landlord.maintenance')->name('landlord.maintenance');
            Route::livewire('requests/complaints', 'pages::landlord.complaints')->name('landlord.complaints');
            Route::livewire('requests/issue-types', 'pages::landlord.issue-types')->name('issue-types');
            Route::get('inbox', fn () => redirect()->route('landlord.maintenance'))->name('inbox');
            Route::livewire('listings', 'pages::landlord.listings')->name('listings');
            Route::livewire('payment-settings', 'pages::landlord.payment-settings')->name('payment-settings');
            Route::livewire('contract-terms', 'pages::landlord.contract-terms')->name('contract-terms');
            Route::livewire('leases/{lease}/contract', 'pages::landlord.lease-contract')
                ->whereNumber('lease')
                ->name('leases.contract');
            Route::livewire('reservations', 'pages::landlord.reservations')->name('reservations');
            Route::get('reservations/{reservation}/files/{kind}', ReservationFileController::class)
                ->whereNumber('reservation')
                ->whereIn('kind', ['id', 'proof'])
                ->name('reservations.files');
        });
    });

require __DIR__.'/settings.php';
