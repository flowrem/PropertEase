<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('a user can add, change and clear their mobile number', function (string $typed, ?string $stored) {
    $user = User::factory()->create(['contact_number' => '+639170000000']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->assertSet('contact_number', '0917 000 0000')
        ->set('contact_number', $typed)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->contact_number)->toBe($stored);
})->with([
    'local format' => ['0918 765 4321', '+639187654321'],
    'international format' => ['+639187654321', '+639187654321'],
    'cleared' => ['', null],
]);

test('an invalid mobile number is rejected on the profile', function () {
    $user = User::factory()->create(['contact_number' => '+639170000000']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('contact_number', '12345')
        ->call('updateProfileInformation')
        ->assertHasErrors(['contact_number']);

    expect($user->refresh()->contact_number)->toBe('+639170000000');
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
