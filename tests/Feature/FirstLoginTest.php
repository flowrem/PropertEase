<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * A tenant account as ApproveReservation creates it, with a known temporary password.
 */
function tenantWithTemporaryPassword(array $overrides = []): User
{
    $user = User::factory()->unverified()->create([
        'email' => 'juana@example.test',
        'password' => 'Temp-Password-123',
    ]);

    $user->forceFill(array_merge([
        'username' => 'juana_dc',
        'must_change_password' => true,
        'temporary_password_expires_at' => now()->addHours(72),
    ], $overrides))->save();

    return $user;
}

test('a user can log in with their email or their username', function (string $identifier) {
    $user = tenantWithTemporaryPassword();

    $this->post(route('login.store'), ['email' => $identifier, 'password' => 'Temp-Password-123'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
})->with(['email' => 'juana@example.test', 'username' => 'juana_dc', 'mixed case' => 'Juana_DC']);

test('a wrong password gives the generic error', function () {
    tenantWithTemporaryPassword();

    $this->post(route('login.store'), ['email' => 'juana_dc', 'password' => 'nope'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an expired temporary password is refused at login', function () {
    tenantWithTemporaryPassword(['temporary_password_expires_at' => now()->subMinute()]);

    $this->post(route('login.store'), ['email' => 'juana_dc', 'password' => 'Temp-Password-123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a disabled account is refused at login', function () {
    tenantWithTemporaryPassword(['disabled_at' => now(), 'must_change_password' => false]);

    $this->post(route('login.store'), ['email' => 'juana_dc', 'password' => 'Temp-Password-123'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('the refusal messages are not revealed without the right password', function () {
    tenantWithTemporaryPassword(['disabled_at' => now()]);

    $this->post(route('login.store'), ['email' => 'juana_dc', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
});

test('the login rate limiter still applies to usernames', function () {
    tenantWithTemporaryPassword();
    RateLimiter::clear('juana_dc|127.0.0.1');

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => 'juana_dc', 'password' => 'wrong']);
    }

    $this->post(route('login.store'), ['email' => 'juana_dc', 'password' => 'Temp-Password-123'])
        ->assertStatus(429);
});

test('a flagged user is redirected to the change page from everywhere', function (string $routeName) {
    $user = tenantWithTemporaryPassword();
    $user->email_verified_at = now();
    $user->save();

    $this->actingAs($user)
        ->get(route($routeName, ['current_team' => $user->currentTeam->slug]))
        ->assertRedirect(route('password.change'));
})->with(['dashboard', 'billing', 'profile.edit', 'teams.index']);

test('a flagged user cannot use the Livewire update endpoint', function () {
    $user = tenantWithTemporaryPassword();
    $this->actingAs($user);

    $this->post(Livewire::getUpdateUri(), ['components' => []])
        ->assertRedirect(route('password.change'));
});

test('the change page renders for a flagged user without verifying their email first', function () {
    $user = tenantWithTemporaryPassword();

    $this->actingAs($user)->get(route('password.change'))->assertOk()->assertSee('Temporary password');
});

test('changing the password logs out, clears the flag and verifies the email', function () {
    $user = tenantWithTemporaryPassword();

    $this->actingAs($user)
        ->post(route('password.change.store'), [
            'current_password' => 'Temp-Password-123',
            'password' => 'A-Brand-New-Pass-456',
            'password_confirmation' => 'A-Brand-New-Pass-456',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Password updated. Log in with your new password.');

    $this->assertGuest();

    $user->refresh();
    expect($user->must_change_password)->toBeFalse()
        ->and($user->temporary_password_expires_at)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('A-Brand-New-Pass-456', $user->password))->toBeTrue();

    $this->post(route('login.store'), ['email' => 'juana_dc', 'password' => 'Temp-Password-123'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => 'juana_dc', 'password' => 'A-Brand-New-Pass-456']);
    $this->assertAuthenticatedAs($user);
});

test('the new password must differ from the temporary one and be confirmed', function () {
    $user = tenantWithTemporaryPassword();

    $this->actingAs($user)
        ->post(route('password.change.store'), [
            'current_password' => 'Temp-Password-123',
            'password' => 'Temp-Password-123',
            'password_confirmation' => 'Temp-Password-123',
        ])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->post(route('password.change.store'), [
            'current_password' => 'wrong',
            'password' => 'A-Brand-New-Pass-456',
            'password_confirmation' => 'A-Brand-New-Pass-456',
        ])
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

test('a user without a temporary password is sent away from the change page', function () {
    $user = User::factory()->create();
    $user->switchTeam($user->currentTeam);

    $this->actingAs($user)->get(route('password.change'))->assertRedirect(route('home'));
    $this->actingAs($user)->post(route('password.change.store'), [
        'current_password' => 'password',
        'password' => 'A-Brand-New-Pass-456',
        'password_confirmation' => 'A-Brand-New-Pass-456',
    ])->assertForbidden();
});

test('a flagged user whose temporary password expired mid-session is logged out', function () {
    $user = tenantWithTemporaryPassword(['temporary_password_expires_at' => now()->subMinute()]);

    $this->actingAs($user)->get(route('password.change'))->assertRedirect(route('login'));

    $this->assertGuest();
});
