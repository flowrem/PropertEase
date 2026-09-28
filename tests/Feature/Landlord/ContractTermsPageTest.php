<?php

use App\Enums\TeamRole;
use App\Models\ContractTemplate;
use App\Models\User;
use Livewire\Livewire;

function contractTermsMember(User $landlord, TeamRole $role): User
{
    $member = User::factory()->create();
    $landlord->currentTeam->members()->attach($member, ['role' => $role]);
    $member->switchTeam($landlord->currentTeam);

    return $member;
}

test('tenants cannot open the contract terms page', function () {
    $landlord = User::factory()->create();
    $tenant = contractTermsMember($landlord, TeamRole::Tenant);

    $this->actingAs($tenant)->get(route('contract-terms'))->assertForbidden();
});

test('a landlord starts from the default terms before saving any', function () {
    $landlord = User::factory()->create();
    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('contract-terms'))->assertOk();

    Livewire::actingAs($landlord)
        ->test('pages::landlord.contract-terms')
        ->assertSet('advance_months', '1')
        ->assertSet('deposit_months', '2')
        ->assertSet('minimum_stay_months_short', '1')
        ->assertSet('minimum_stay_months_long', '12')
        ->assertSet('notice_days', '30')
        ->assertSet('late_fee', '');

    expect(ContractTemplate::count())->toBe(0);
});

test('a landlord or manager saves the team\'s terms', function (TeamRole $role) {
    $landlord = User::factory()->create();
    $user = $role === TeamRole::Owner ? $landlord : contractTermsMember($landlord, $role);

    Livewire::actingAs($user)
        ->test('pages::landlord.contract-terms')
        ->set('advance_months', '2')
        ->set('deposit_months', '1')
        ->set('minimum_stay_months_short', '3')
        ->set('minimum_stay_months_long', '6')
        ->set('notice_days', '15')
        ->set('late_fee', '250')
        ->set('house_rules', '  Quiet hours from 10 PM.  ')
        ->call('save')
        ->assertHasNoErrors();

    $terms = $landlord->currentTeam->fresh()->contractTerms();

    expect($terms->exists)->toBeTrue()
        ->and($terms->advance_months)->toBe(2)
        ->and($terms->deposit_months)->toBe(1)
        ->and($terms->minimum_stay_months_short)->toBe(3)
        ->and($terms->minimum_stay_months_long)->toBe(6)
        ->and($terms->notice_days)->toBe(15)
        ->and($terms->late_fee)->toBe('250.00')
        ->and($terms->house_rules)->toBe('Quiet hours from 10 PM.')
        ->and($terms->additional_terms)->toBeNull();
})->with([TeamRole::Owner, TeamRole::Admin]);

test('the terms stay within their limits', function (string $field, string $value) {
    $landlord = User::factory()->create();

    Livewire::actingAs($landlord)
        ->test('pages::landlord.contract-terms')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field]);

    expect(ContractTemplate::count())->toBe(0);
})->with([
    'more than 3 months advance' => ['advance_months', '4'],
    'negative deposit' => ['deposit_months', '-1'],
    'no short-term minimum' => ['minimum_stay_months_short', '0'],
    'long-term over 3 years' => ['minimum_stay_months_long', '37'],
    'long-term shorter than short-term' => ['minimum_stay_months_long', '0'],
    'notice over 90 days' => ['notice_days', '91'],
    'zero late fee' => ['late_fee', '0'],
    'house rules too long' => ['house_rules', str_repeat('a', 5001)],
]);

test('a long-term minimum shorter than the short-term one is refused', function () {
    $landlord = User::factory()->create();

    Livewire::actingAs($landlord)
        ->test('pages::landlord.contract-terms')
        ->set('minimum_stay_months_short', '6')
        ->set('minimum_stay_months_long', '3')
        ->call('save')
        ->assertHasErrors(['minimum_stay_months_long' => 'gte']);
});

test('staff see the terms but cannot save them', function () {
    $landlord = User::factory()->create();
    $staff = contractTermsMember($landlord, TeamRole::Member);

    Livewire::actingAs($staff)
        ->test('pages::landlord.contract-terms')
        ->assertSee('You can view these terms.')
        ->assertDontSee('Save terms')
        ->set('advance_months', '3')
        ->call('save')
        ->assertForbidden();

    expect(ContractTemplate::count())->toBe(0);
});

test('each team keeps its own terms', function () {
    $landlord = User::factory()->create();
    $other = User::factory()->create();
    ContractTemplate::factory()->for($other->currentTeam)->create(['advance_months' => 3]);

    Livewire::actingAs($landlord)
        ->test('pages::landlord.contract-terms')
        ->assertSet('advance_months', '1')
        ->set('advance_months', '2')
        ->call('save');

    expect($other->currentTeam->fresh()->contractTerms()->advance_months)->toBe(3)
        ->and($landlord->currentTeam->fresh()->contractTerms()->advance_months)->toBe(2);
});
