<?php

use App\Enums\PaymentMethod;
use App\Enums\TeamRole;
use App\Models\PaymentChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('media');
});

function teamMemberWithRole(User $landlord, TeamRole $role): User
{
    $member = User::factory()->create();
    $landlord->currentTeam->members()->attach($member, ['role' => $role]);
    $member->switchTeam($landlord->currentTeam);

    return $member;
}

test('guests are redirected to the login page', function () {
    User::factory()->create();

    $this->get(route('payment-settings'))->assertRedirect(route('login'));
});

test('tenants cannot open payment settings', function () {
    $landlord = User::factory()->create();
    $tenant = teamMemberWithRole($landlord, TeamRole::Tenant);

    $this->actingAs($tenant)->get(route('payment-settings'))->assertForbidden();
});

test('a landlord can add a GCash channel with a QR code', function () {
    $landlord = User::factory()->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')
        ->call('openCreate')
        ->set('method', 'gcash')
        ->set('account_name', 'Maria Santos')
        ->set('qr', UploadedFile::fake()->image('my-gcash.png'))
        ->call('save')
        ->assertHasNoErrors();

    $channel = $landlord->currentTeam->paymentChannels()->sole();

    expect($channel->method)->toBe(PaymentMethod::Gcash)
        ->and($channel->is_active)->toBeTrue()
        ->and($channel->qr_path)->not->toContain('my-gcash');

    Storage::disk('media')->assertExists($channel->qr_path);
});

test('a GCash channel requires a QR code', function () {
    $landlord = User::factory()->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')
        ->call('openCreate')
        ->set('method', 'gcash')
        ->set('account_name', 'Maria Santos')
        ->call('save')
        ->assertHasErrors(['qr' => 'required']);

    expect(PaymentChannel::count())->toBe(0);
});

test('a bank channel requires bank name and account number but not a QR', function () {
    $landlord = User::factory()->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')
        ->call('openCreate')
        ->set('method', 'bank_transfer')
        ->set('account_name', 'Maria Santos')
        ->call('save')
        ->assertHasErrors(['bank_name', 'account_number'])
        ->set('bank_name', 'BDO')
        ->set('account_number', '0012345678')
        ->call('save')
        ->assertHasNoErrors();

    expect($landlord->currentTeam->paymentChannels()->sole()->qr_path)->toBeNull();
});

test('QR uploads must be small images', function (UploadedFile $file) {
    $landlord = User::factory()->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')
        ->call('openCreate')
        ->set('account_name', 'Maria Santos')
        ->set('qr', $file)
        ->call('save')
        ->assertHasErrors('qr');
})->with([
    'a pdf' => fn () => UploadedFile::fake()->create('qr.pdf', 100, 'application/pdf'),
    'over 2 MB' => fn () => UploadedFile::fake()->image('qr.png')->size(3000),
]);

test('only online methods can be offered', function () {
    $landlord = User::factory()->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')
        ->call('openCreate')
        ->set('method', 'cash')
        ->set('account_name', 'Maria Santos')
        ->call('save')
        ->assertHasErrors('method');
});

test('replacing a QR code removes the old file', function () {
    $landlord = User::factory()->create();
    $channel = PaymentChannel::factory()->for($landlord->currentTeam)->create(['qr_path' => 'qr/old.png']);
    Storage::disk('media')->put('qr/old.png', 'old');

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')
        ->call('openEdit', $channel->id)
        ->set('qr', UploadedFile::fake()->image('new.png'))
        ->call('save')
        ->assertHasNoErrors();

    Storage::disk('media')->assertMissing('qr/old.png');
    Storage::disk('media')->assertExists($channel->refresh()->qr_path);
});

test('a landlord can deactivate and reactivate a channel', function () {
    $landlord = User::factory()->create();
    $channel = PaymentChannel::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')->call('toggleActive', $channel->id);
    expect($channel->refresh()->is_active)->toBeFalse();

    Livewire::test('pages::landlord.payment-settings')->call('toggleActive', $channel->id);
    expect($channel->refresh()->is_active)->toBeTrue();
});

test('a manager can manage channels', function () {
    $landlord = User::factory()->create();
    $manager = teamMemberWithRole($landlord, TeamRole::Admin);
    $channel = PaymentChannel::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($manager);

    Livewire::test('pages::landlord.payment-settings')->call('toggleActive', $channel->id);

    expect($channel->refresh()->is_active)->toBeFalse();
});

test('staff can view channels but not change them', function () {
    $landlord = User::factory()->create();
    $staff = teamMemberWithRole($landlord, TeamRole::Member);
    $channel = PaymentChannel::factory()->for($landlord->currentTeam)->create(['account_name' => 'Maria Santos']);

    $this->actingAs($staff);

    Livewire::test('pages::landlord.payment-settings')
        ->assertSee('Maria Santos')
        ->assertDontSee('Add channel')
        ->assertDontSee('Deactivate');

    Livewire::test('pages::landlord.payment-settings')->call('toggleActive', $channel->id)->assertForbidden();
    Livewire::test('pages::landlord.payment-settings')->call('openCreate')->assertForbidden();
    Livewire::test('pages::landlord.payment-settings')
        ->set('account_name', 'Sneaky')
        ->set('qr', UploadedFile::fake()->image('qr.png'))
        ->call('save')
        ->assertForbidden();

    expect($channel->refresh()->is_active)->toBeTrue()
        ->and(PaymentChannel::count())->toBe(1);
});

test('a landlord cannot touch another team\'s channel', function () {
    $landlord = User::factory()->create();
    $otherLandlord = User::factory()->create();
    $otherChannel = PaymentChannel::factory()->for($otherLandlord->currentTeam)->create();

    $this->actingAs($landlord);

    $page = Livewire::test('pages::landlord.payment-settings');

    expect(fn () => $page->call('openEdit', $otherChannel->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $page->call('toggleActive', $otherChannel->id))->toThrow(ModelNotFoundException::class);
    expect($otherChannel->refresh()->is_active)->toBeTrue();
});

test('a landlord only sees their own channels', function () {
    $landlord = User::factory()->create();
    $otherLandlord = User::factory()->create();
    PaymentChannel::factory()->for($otherLandlord->currentTeam)->create(['account_name' => 'Someone Else']);

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')->assertDontSee('Someone Else');
});

test('the page warns when there is no active channel', function () {
    $landlord = User::factory()->create();
    PaymentChannel::factory()->for($landlord->currentTeam)->inactive()->create();

    $this->actingAs($landlord);

    Livewire::test('pages::landlord.payment-settings')->assertSee('no active payment channel');
});

test('a landlord or manager sets how many days an accepted reservation holds the unit', function (TeamRole $role) {
    $landlord = User::factory()->create();
    $user = $role === TeamRole::Owner ? $landlord : teamMemberWithRole($landlord, $role);

    Livewire::actingAs($user)
        ->test('pages::landlord.payment-settings')
        ->assertSet('reservationHoldDays', '3')
        ->set('reservationHoldDays', '7')
        ->call('saveReservationHoldDays')
        ->assertHasNoErrors();

    expect($landlord->currentTeam->fresh()->reservation_hold_days)->toBe(7);
})->with([TeamRole::Owner, TeamRole::Admin]);

test('the reservation hold must be 1 to 14 days', function (string $days) {
    $landlord = User::factory()->create();

    Livewire::actingAs($landlord)
        ->test('pages::landlord.payment-settings')
        ->set('reservationHoldDays', $days)
        ->call('saveReservationHoldDays')
        ->assertHasErrors(['reservationHoldDays']);

    expect($landlord->currentTeam->fresh()->reservation_hold_days)->toBe(3);
})->with(['0', '15', '', 'two']);

test('staff see the reservation hold but cannot change it', function () {
    $landlord = User::factory()->create();
    $staff = teamMemberWithRole($landlord, TeamRole::Member);

    Livewire::actingAs($staff)
        ->test('pages::landlord.payment-settings')
        ->assertSee('3 days')
        ->set('reservationHoldDays', '7')
        ->call('saveReservationHoldDays')
        ->assertForbidden();

    expect($landlord->currentTeam->fresh()->reservation_hold_days)->toBe(3);
});
