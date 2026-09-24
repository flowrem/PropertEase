<?php

use App\Models\Property;
use App\Models\User;

test('the logo files and icons exist', function (string $path) {
    expect(public_path($path))->toBeFile();
})->with([
    'images/logo.png',
    'images/logo-mark.png',
    'apple-touch-icon.png',
    'icon-192.png',
    'favicon-32.png',
    'favicon.ico',
]);

test('the favicon is a real icon file and the old placeholder svg is gone', function () {
    expect(bin2hex(substr(file_get_contents(public_path('favicon.ico')), 0, 4)))->toBe('00000100')
        ->and(public_path('favicon.svg'))->not->toBeFile();
});

test('the login and register pages show the full logo', function (string $route) {
    $this->get(route($route))
        ->assertOk()
        ->assertSee('images/logo.png', false)
        ->assertDontSee('favicon.svg', false);
})->with(['login', 'register']);

test('the public pages show the logo mark and the new favicon links', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('images/logo-mark.png', false)
        ->assertSee('favicon-32.png', false)
        ->assertSee('apple-touch-icon.png', false);
});

test('the app sidebar shows the logo mark for a logged in landlord', function () {
    $landlord = User::factory()->create();
    Property::factory()->for($landlord->currentTeam)->create();
    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('images/logo-mark.png', false);
});
