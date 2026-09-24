<?php

use App\Models\User;

test('creating a super admin with a new email makes a teamless super admin account', function () {
    $this->artisan('app:create-super-admin')
        ->expectsQuestion('Email address', 'admin@example.com')
        ->expectsQuestion('Name', 'Site Admin')
        ->expectsQuestion('Password', 'super-secret-password')
        ->expectsQuestion('Confirm password', 'super-secret-password')
        ->assertExitCode(0);

    $user = User::where('email', 'admin@example.com')->firstOrFail();

    expect($user->is_super_admin)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->current_team_id)->toBeNull()
        ->and($user->personalTeam())->toBeNull();
});

test('creating a super admin fails when the password confirmation does not match', function () {
    $this->artisan('app:create-super-admin')
        ->expectsQuestion('Email address', 'admin@example.com')
        ->expectsQuestion('Name', 'Site Admin')
        ->expectsQuestion('Password', 'super-secret-password')
        ->expectsQuestion('Confirm password', 'different-password')
        ->assertExitCode(1);

    $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
});

test('giving an existing email promotes that user to super admin after confirmation', function () {
    $user = User::factory()->create(['email' => 'landlord@example.com']);

    $this->artisan('app:create-super-admin')
        ->expectsQuestion('Email address', 'landlord@example.com')
        ->expectsConfirmation("Promote {$user->name} ({$user->email}) to Super Admin?", 'yes')
        ->assertExitCode(0);

    expect($user->fresh()->is_super_admin)->toBeTrue();
});

test('declining the promotion confirmation leaves the user unchanged', function () {
    $user = User::factory()->create(['email' => 'landlord@example.com']);

    $this->artisan('app:create-super-admin')
        ->expectsQuestion('Email address', 'landlord@example.com')
        ->expectsConfirmation("Promote {$user->name} ({$user->email}) to Super Admin?", 'no')
        ->assertExitCode(0);

    expect($user->fresh()->is_super_admin)->toBeFalse();
});
