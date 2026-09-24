<?php

use App\Actions\Landlords\ApproveLandlord;
use App\Actions\Landlords\RejectLandlord;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LandlordApproved;
use App\Notifications\LandlordRejected;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake(config('filesystems.sensitive_disk'));
});

function reviewer(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    return $admin;
}

/**
 * A landlord whose ID is waiting for review, with the ID file on the private disk.
 *
 * @return array{0: User, 1: Team}
 */
function pendingLandlord(): array
{
    $landlord = User::factory()->create();
    $team = $landlord->currentTeam;

    $team->forceFill([
        'approved_at' => null,
        'verification_id_path' => "landlord-ids/{$team->id}/id.jpg",
        'verification_submitted_at' => now(),
    ])->save();

    Storage::disk(config('filesystems.sensitive_disk'))->put($team->verification_id_path, 'id-bytes');

    return [$landlord, $team->fresh()];
}

test('a Super Admin can approve a landlord, who is then emailed and unblocked', function () {
    Notification::fake();
    [$landlord, $team] = pendingLandlord();
    $admin = reviewer();

    app(ApproveLandlord::class)->handle($team, $admin);

    $team->refresh();
    expect($team->isApproved())->toBeTrue()
        ->and($team->approved_by)->toBe($admin->id)
        ->and($landlord->fresh()->teamAwaitingApproval())->toBeNull();

    Notification::assertSentTo($landlord, LandlordApproved::class);
});

test('rejecting needs a reason, stores it and emails the landlord', function () {
    Notification::fake();
    [$landlord, $team] = pendingLandlord();
    $admin = reviewer();

    expect(fn () => app(RejectLandlord::class)->handle($team, $admin, '  '))->toThrow(ValidationException::class);

    app(RejectLandlord::class)->handle($team, $admin, 'The ID is blurry.');

    $team->refresh();
    expect($team->isRejected())->toBeTrue()
        ->and($team->rejection_reason)->toBe('The ID is blurry.');

    Notification::assertSentTo($landlord, LandlordRejected::class);
});

test('a team can only be reviewed once until it resubmits', function () {
    Notification::fake();
    [, $team] = pendingLandlord();
    $admin = reviewer();

    app(ApproveLandlord::class)->handle($team, $admin);

    expect(fn () => app(ApproveLandlord::class)->handle($team->fresh(), $admin))->toThrow(ValidationException::class)
        ->and(fn () => app(RejectLandlord::class)->handle($team->fresh(), $admin, 'Too late'))->toThrow(ValidationException::class);

    Notification::assertSentTimes(LandlordApproved::class, 1);
});

test('only a Super Admin can approve or reject', function () {
    [, $team] = pendingLandlord();
    $notAdmin = User::factory()->create();

    expect(fn () => app(ApproveLandlord::class)->handle($team, $notAdmin))->toThrow(HttpException::class)
        ->and(fn () => app(RejectLandlord::class)->handle($team, $notAdmin, 'No'))->toThrow(HttpException::class);

    expect($team->fresh()->isApproved())->toBeFalse();
});

test('the landlords page lists pending landlords and lets a Super Admin approve them', function () {
    Notification::fake();
    [$landlord, $team] = pendingLandlord();

    $this->actingAs(reviewer());

    Livewire::test('pages::admin.landlords')
        ->assertSee($team->name)
        ->assertSee('View ID')
        ->call('approve', $team->id)
        ->assertHasNoErrors();

    expect($team->fresh()->isApproved())->toBeTrue();
});

test('the landlords page rejects with a reason', function () {
    Notification::fake();
    [, $team] = pendingLandlord();

    $this->actingAs(reviewer());

    Livewire::test('pages::admin.landlords')
        ->call('startRejecting', $team->id)
        ->call('reject')
        ->assertHasErrors('reason')
        ->set('rejectReason', 'Name does not match.')
        ->call('reject')
        ->assertHasNoErrors();

    expect($team->fresh()->isRejected())->toBeTrue();
});

test('the landlord ID is streamed privately to a Super Admin only', function () {
    [, $team] = pendingLandlord();
    $url = route('admin.landlords.id', ['team' => $team->id]);

    $response = $this->actingAs(reviewer())->get($url)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
});

test('a pruned landlord ID is no longer served', function () {
    [, $team] = pendingLandlord();
    $team->forceFill(['verification_id_pruned_at' => now()])->save();

    $this->actingAs(reviewer())
        ->get(route('admin.landlords.id', ['team' => $team->id]))
        ->assertNotFound();
});

test('pruning deletes IDs thirty days after a decision and keeps the rest', function () {
    $disk = Storage::disk(config('filesystems.sensitive_disk'));

    [, $old] = pendingLandlord();
    [, $recent] = pendingLandlord();
    [, $undecided] = pendingLandlord();

    $old->forceFill(['approved_at' => now()->subDays(31)])->save();
    $recent->forceFill(['approved_at' => now()->subDays(5)])->save();

    $this->artisan('landlord-ids:prune')->assertSuccessful();

    $disk->assertMissing($old->verification_id_path);
    $disk->assertExists($recent->verification_id_path);
    $disk->assertExists($undecided->verification_id_path);
    expect($old->fresh()->verification_id_pruned_at)->not->toBeNull()
        ->and($recent->fresh()->verification_id_pruned_at)->toBeNull();
});

test('pruning also covers rejected landlords', function () {
    [, $team] = pendingLandlord();
    $team->forceFill(['rejected_at' => now()->subDays(40), 'rejection_reason' => 'No.'])->save();

    $this->artisan('landlord-ids:prune')->assertSuccessful();

    Storage::disk(config('filesystems.sensitive_disk'))->assertMissing($team->verification_id_path);
});
