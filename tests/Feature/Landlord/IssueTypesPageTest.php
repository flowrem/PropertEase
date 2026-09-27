<?php

use App\Enums\TeamRole;
use App\Models\IssueType;
use App\Models\User;
use Livewire\Livewire;

test('a landlord sees the platform issue types and can add their own', function () {
    $landlord = User::factory()->create();

    $this->actingAs($landlord)->get(route('issue-types'))->assertOk()->assertSee('Leaking faucet');

    Livewire::actingAs($landlord)->test('pages::landlord.issue-types')
        ->set('name', '  Gate remote not working ')
        ->call('addIssueType')
        ->assertHasNoErrors();

    expect($landlord->currentTeam->issueTypes()->sole()->name)->toBe('Gate remote not working');
});

test('an issue type cannot repeat a name already on the list, ignoring case', function (string $name) {
    $landlord = User::factory()->create();
    IssueType::factory()->for($landlord->currentTeam)->create(['name' => 'Gate remote not working']);

    Livewire::actingAs($landlord)->test('pages::landlord.issue-types')
        ->set('name', $name)
        ->call('addIssueType')
        ->assertHasErrors(['name']);
})->with([
    'a platform default' => 'leaking FAUCET',
    'one of the team\'s own' => 'Gate Remote Not Working',
]);

test('a landlord can deactivate and reactivate their own issue type', function () {
    $landlord = User::factory()->create();
    $issueType = IssueType::factory()->for($landlord->currentTeam)->create();

    Livewire::actingAs($landlord)->test('pages::landlord.issue-types')->call('toggleActive', $issueType->id);
    expect($issueType->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($landlord)->test('pages::landlord.issue-types')->call('toggleActive', $issueType->id);
    expect($issueType->fresh()->is_active)->toBeTrue();
});

test('staff can view issue types but not change them, and tenants cannot open the page', function () {
    $landlord = User::factory()->create();
    $issueType = IssueType::factory()->for($landlord->currentTeam)->create();
    $staff = User::factory()->create();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $staff->switchTeam($landlord->currentTeam);

    Livewire::actingAs($staff)->test('pages::landlord.issue-types')
        ->assertSee('You can view this list')
        ->set('name', 'Roof leak')
        ->call('addIssueType')
        ->assertForbidden();
    Livewire::actingAs($staff)->test('pages::landlord.issue-types')->call('toggleActive', $issueType->id)->assertForbidden();

    $tenant->switchTeam($landlord->currentTeam);
    $this->actingAs($tenant)->get(route('issue-types'))->assertForbidden();
});
