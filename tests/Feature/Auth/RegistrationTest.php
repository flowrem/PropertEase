<?php

use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->first();

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    expect($user->personalTeam())->not->toBeNull();
});

test('registering through a valid invitation joins that team instead of a personal team', function () {
    $landlord = User::factory()->create();
    $team = $landlord->currentTeam;

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited-tenant@example.com',
        'role' => TeamRole::Tenant,
        'invited_by' => $landlord->id,
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Invited Tenant',
        'email' => 'invited-tenant@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $user = User::where('email', 'invited-tenant@example.com')->firstOrFail();

    expect($user->personalTeam())->toBeNull()
        ->and($user->belongsToTeam($team))->toBeTrue()
        ->and($user->teamRole($team))->toBe(TeamRole::Tenant)
        ->and($user->fresh()->current_team_id)->toBe($team->id)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('registering with an email that does not match the invitation gets a personal team instead', function () {
    $landlord = User::factory()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $landlord->currentTeam->id,
        'email' => 'invited-tenant@example.com',
        'role' => TeamRole::Tenant,
        'invited_by' => $landlord->id,
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Someone Else',
        'email' => 'someone-else@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'someone-else@example.com')->firstOrFail();

    expect($user->personalTeam())->not->toBeNull()
        ->and($user->belongsToTeam($landlord->currentTeam))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});
