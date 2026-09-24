<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernPriority;
use App\Enums\ConcernStatus;
use App\Enums\TeamRole;
use App\Models\Concern;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\User;

function concernForTeam(Team $team, array $attributes): Concern
{
    $lease = Lease::factory()->for(Unit::factory()->for(Property::factory()->for($team)->create())->create())->create();

    return Concern::factory()->for($lease)->create($attributes);
}

test('guests are redirected to the login page', function () {
    User::factory()->create();

    $this->get(route('landlord.maintenance'))->assertRedirect(route('login'));
    $this->get(route('landlord.complaints'))->assertRedirect(route('login'));
    $this->get(route('inbox'))->assertRedirect(route('login'));
});

test('tenants cannot access the maintenance or complaints pages', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $this->actingAs($tenant)->get(route('landlord.maintenance'))->assertForbidden();
    $this->actingAs($tenant)->get(route('landlord.complaints'))->assertForbidden();
});

test('the old inbox address redirects to maintenance', function () {
    $landlord = User::factory()->create();
    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('inbox'))->assertRedirect(route('landlord.maintenance'));
});

test('a landlord only sees maintenance requests from their own team', function () {
    $landlord = User::factory()->create();
    concernForTeam($landlord->currentTeam, ['category' => ConcernCategory::Maintenance, 'title' => 'Leaking faucet in unit']);
    concernForTeam(Team::factory()->create(), ['category' => ConcernCategory::Maintenance, 'title' => 'Broken gate in another building']);

    $landlord->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('landlord.maintenance'))
        ->assertOk()
        ->assertSee('Leaking faucet in unit')
        ->assertDontSee('Broken gate in another building');
});

test('the maintenance page shows only maintenance, most urgent first', function () {
    $landlord = User::factory()->create();
    $team = $landlord->currentTeam;
    concernForTeam($team, ['category' => ConcernCategory::Maintenance, 'title' => 'Low priority repair', 'priority' => ConcernPriority::Low, 'status' => ConcernStatus::Pending]);
    concernForTeam($team, ['category' => ConcernCategory::Maintenance, 'title' => 'Urgent repair', 'priority' => ConcernPriority::Urgent, 'status' => ConcernStatus::Pending]);
    concernForTeam($team, ['category' => ConcernCategory::Maintenance, 'title' => 'Fixed already', 'priority' => ConcernPriority::Urgent, 'status' => ConcernStatus::Resolved]);
    concernForTeam($team, ['category' => ConcernCategory::Complaint, 'title' => 'Noise complaint']);

    $landlord->switchTeam($team);

    $this->actingAs($landlord)->get(route('landlord.maintenance'))
        ->assertOk()
        ->assertDontSee('Noise complaint')
        ->assertSeeInOrder(['Urgent repair', 'Low priority repair', 'Fixed already']);
});

test('the complaints page shows only complaints from this team', function () {
    $landlord = User::factory()->create();
    $team = $landlord->currentTeam;
    concernForTeam($team, ['category' => ConcernCategory::Complaint, 'title' => 'Noise complaint']);
    concernForTeam($team, ['category' => ConcernCategory::Maintenance, 'title' => 'Leaking faucet']);
    concernForTeam(Team::factory()->create(), ['category' => ConcernCategory::Complaint, 'title' => 'Other team complaint']);

    $landlord->switchTeam($team);

    $this->actingAs($landlord)->get(route('landlord.complaints'))
        ->assertOk()
        ->assertSee('Noise complaint')
        ->assertDontSee('Leaking faucet')
        ->assertDontSee('Other team complaint');
});
