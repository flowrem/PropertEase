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
 * The mobile number, valid ID and consent every self-registering landlord must send.
 *
 * @return array{contact_number: string, verification_id: UploadedFile, consent: string}
 */
function landlordSignUpPayload(): array
{
    return [
        'contact_number' => '09171234567',
        'verification_id' => UploadedFile::fake()->image('id.jpg'),
        'consent' => '1',
    ];
}

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register as a landlord whose team is named after them', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...landlordSignUpPayload(),
    ]);

    $user = User::where('email', 'test@example.com')->first();

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    expect($user->personalTeam())->not->toBeNull()
        ->and($user->personalTeam()->name)->toBe('John Doe')
        ->and($user->contact_number)->toBe('+639171234567');
});

test('a landlord cannot register without a valid mobile number', function (string $typed) {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...landlordSignUpPayload(),
        'contact_number' => $typed,
    ]);

    $response->assertSessionHasErrors('contact_number');

    $this->assertGuest();
})->with([
    'missing' => [''],
    'one digit short' => ['0917123456'],
    'not a mobile number' => ['02123456789'],
]);

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
        ...landlordSignUpPayload(),
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'someone-else@example.com')->firstOrFail();

    expect($user->personalTeam())->not->toBeNull()
        ->and($user->belongsToTeam($landlord->currentTeam))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});
