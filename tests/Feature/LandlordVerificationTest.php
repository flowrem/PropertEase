<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('filesystems.sensitive_disk'));
});

/**
 * @return array<string, mixed>
 */
function landlordRegistration(array $overrides = []): array
{
    return array_merge([
        'name' => 'Juana Dela Cruz',
        'email' => 'juana@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'business_name' => 'Juana Apartments',
        'verification_id' => UploadedFile::fake()->image('id.jpg'),
        'consent' => '1',
    ], $overrides);
}

function landlordAwaitingApproval(bool $rejected = false): User
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $team->forceFill([
        'approved_at' => null,
        'verification_id_path' => 'landlord-ids/'.$team->id.'/old.jpg',
        'verification_submitted_at' => now(),
        'rejected_at' => $rejected ? now() : null,
        'rejection_reason' => $rejected ? 'The ID is blurry.' : null,
    ])->save();

    Storage::disk(config('filesystems.sensitive_disk'))->put($team->verification_id_path, 'old-id');

    $user->switchTeam($team);

    return $user->fresh();
}

test('registering as a landlord stores the ID privately and leaves the team unapproved', function () {
    $this->post(route('register.store'), landlordRegistration())->assertSessionHasNoErrors();

    $team = User::where('email', 'juana@example.test')->firstOrFail()->personalTeam();

    expect($team->isApproved())->toBeFalse()
        ->and($team->isAwaitingReview())->toBeTrue()
        ->and($team->verification_submitted_at)->not->toBeNull();

    Storage::disk(config('filesystems.sensitive_disk'))->assertExists($team->verification_id_path);
});

test('a landlord must upload a valid ID and give consent', function (array $overrides, string $field) {
    $this->post(route('register.store'), landlordRegistration($overrides))->assertSessionHasErrors($field);

    expect(User::where('email', 'juana@example.test')->exists())->toBeFalse();
})->with([
    'no id' => [['verification_id' => null], 'verification_id'],
    'wrong file type' => [['verification_id' => UploadedFile::fake()->create('id.exe', 10)], 'verification_id'],
    'too large' => [['verification_id' => UploadedFile::fake()->create('id.pdf', 6000, 'application/pdf')], 'verification_id'],
    'no consent' => [['consent' => null], 'consent'],
]);

test('a user invited to an approved team does not need an ID', function () {
    $landlord = User::factory()->create();

    $invitation = $landlord->currentTeam->invitations()->create([
        'email' => 'staff@example.test',
        'role' => TeamRole::Member,
        'invited_by' => $landlord->id,
        'expires_at' => now()->addDay(),
    ]);

    $this->post(route('register.store'), [
        'name' => 'Staff Person',
        'email' => 'staff@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ])->assertSessionHasNoErrors();

    $staff = User::where('email', 'staff@example.test')->firstOrFail();

    expect($staff->teamAwaitingApproval())->toBeNull();
});

test('an unapproved landlord is sent to the review page from every landlord page', function (string $routeName) {
    $user = landlordAwaitingApproval();

    $this->actingAs($user)
        ->get(route($routeName, ['current_team' => $user->currentTeam->slug]))
        ->assertRedirect(route('landlord.verification'));
})->with(['dashboard', 'properties', 'tenants', 'listings', 'payment-settings', 'reservations', 'profile.edit', 'teams.index']);

test('an unapproved landlord cannot use the Livewire update endpoint', function () {
    $this->actingAs(landlordAwaitingApproval());

    $this->post(Livewire::getUpdateUri(), ['components' => []])
        ->assertRedirect(route('landlord.verification'));
});

test('an unapproved landlord can still reach the review page, the public pages and logout', function () {
    $user = landlordAwaitingApproval();

    $this->actingAs($user)->get(route('landlord.verification'))->assertOk()->assertSee('under review');
    $this->actingAs($user)->get(route('home'))->assertOk();
    $this->actingAs($user)->get(route('listings.index'))->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect();
});

test('approved landlords, Super Admins and tenants are not blocked', function () {
    $landlord = User::factory()->create();
    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('properties'))->assertOk();

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();
    expect($admin->teamAwaitingApproval())->toBeNull();

    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    expect($tenant->teamAwaitingApproval())->toBeNull();
});

test('a landlord who owns an approved team gets further teams approved automatically', function () {
    $landlord = User::factory()->create();

    expect(app(CreateTeam::class)->handle($landlord, 'Second Property')->isApproved())->toBeTrue();

    $newcomer = landlordAwaitingApproval();

    expect(app(CreateTeam::class)->handle($newcomer, 'Another')->isApproved())->toBeFalse();
});

test('a rejected landlord sees the reason and can resubmit a new ID', function () {
    $user = landlordAwaitingApproval(rejected: true);
    $disk = Storage::disk(config('filesystems.sensitive_disk'));
    $oldPath = $user->currentTeam->verification_id_path;

    $this->actingAs($user)->get(route('landlord.verification'))->assertSee('The ID is blurry.');

    $this->actingAs($user)
        ->post(route('landlord.verification.resubmit'), [
            'verification_id' => UploadedFile::fake()->image('new-id.png'),
            'consent' => '1',
        ])
        ->assertRedirect(route('landlord.verification'));

    $team = $user->currentTeam->fresh();
    expect($team->isAwaitingReview())->toBeTrue()
        ->and($team->rejection_reason)->toBeNull()
        ->and($team->verification_id_path)->not->toBe($oldPath);

    $disk->assertMissing($oldPath);
    $disk->assertExists($team->verification_id_path);
});

test('resubmitting needs a valid file and is only for rejected teams', function () {
    $rejected = landlordAwaitingApproval(rejected: true);

    $this->actingAs($rejected)
        ->post(route('landlord.verification.resubmit'), ['consent' => '1'])
        ->assertSessionHasErrors('verification_id');

    $waiting = landlordAwaitingApproval();

    $this->actingAs($waiting)
        ->post(route('landlord.verification.resubmit'), [
            'verification_id' => UploadedFile::fake()->image('id.jpg'),
            'consent' => '1',
        ])
        ->assertForbidden();
});

test('teams created without verification data default to approved in factories', function () {
    expect(Team::factory()->create()->isApproved())->toBeTrue();
});
