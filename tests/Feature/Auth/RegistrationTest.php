<?php

use App\Enums\TeamRole;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('filesystems.sensitive_disk'));
});

/**
 * The valid ID and consent every self-registering landlord must send.
 *
 * @return array{verification_id: UploadedFile, consent: string}
 */
function landlordIdPayload(): array
{
    return [
        'verification_id' => UploadedFile::fake()->image('id.jpg'),
        'consent' => '1',
    ];
}

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register as a landlord with a business name', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'business_name' => "John's Apartments",
        ...landlordIdPayload(),
    ]);

    $user = User::where('email', 'test@example.com')->first();

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    expect($user->personalTeam())->not->toBeNull()
        ->and($user->personalTeam()->name)->toBe("John's Apartments");
});

test('registering without a business name falls back to a default team name', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...landlordIdPayload(),
    ]);

    $user = User::where('email', 'test@example.com')->first();

    $response->assertSessionHasNoErrors();

    expect($user->personalTeam()->name)->toBe("John Doe's Team");
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
        ...landlordIdPayload(),
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'someone-else@example.com')->firstOrFail();

    expect($user->personalTeam())->not->toBeNull()
        ->and($user->belongsToTeam($landlord->currentTeam))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});
