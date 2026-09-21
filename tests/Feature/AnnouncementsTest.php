<?php

use App\Enums\TeamRole;
use App\Models\Announcement;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('announcements'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the announcements page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('announcements'));

    $response->assertOk();
});

test('a landlord sees the compose form but a tenant does not', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $this->actingAs($landlord)->get(route('announcements'))->assertSee('Post announcement');
    $this->actingAs($tenant)->get(route('announcements'))->assertDontSee('Post announcement');
});

test('a landlord can post an announcement to a single property', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create(['name' => 'Sunrise Apartments']);

    $this->actingAs($landlord);

    Livewire::test('pages::announcements')
        ->set('propertyId', (string) $property->id)
        ->set('content', 'Water will be shut off Friday from 8am to 12nn.')
        ->call('post')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('announcements', [
        'property_id' => $property->id,
        'author_id' => $landlord->id,
        'content' => 'Water will be shut off Friday from 8am to 12nn.',
    ]);
    $this->assertDatabaseCount('announcements', 1);
});

test('posting to all properties creates one announcement per property', function () {
    $landlord = User::factory()->create();
    $propertyA = Property::factory()->for($landlord->currentTeam)->create();
    $propertyB = Property::factory()->for($landlord->currentTeam)->create();

    $this->actingAs($landlord);

    Livewire::test('pages::announcements')
        ->set('propertyId', 'all')
        ->set('content', 'The building will have a fire drill next week.')
        ->call('post')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('announcements', ['property_id' => $propertyA->id]);
    $this->assertDatabaseHas('announcements', ['property_id' => $propertyB->id]);
    $this->assertDatabaseCount('announcements', 2);
});

test('a tenant cannot post an announcement', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $this->actingAs($tenant);

    Livewire::test('pages::announcements')
        ->set('content', 'I am not allowed to do this.')
        ->call('post')
        ->assertForbidden();

    $this->assertDatabaseCount('announcements', 0);
});

test('a tenant only sees announcements for their own property', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $otherProperty = Property::factory()->for($landlord->currentTeam)->create();

    $unit = Unit::factory()->for($property)->create();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    Lease::factory()->for($unit)->for($tenant, 'tenant')->create();

    Announcement::factory()->for($property)->for($landlord, 'author')->create(['content' => 'Notice for my building']);
    Announcement::factory()->for($otherProperty)->for($landlord, 'author')->create(['content' => 'Notice for a different building']);

    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('announcements'));

    $response
        ->assertOk()
        ->assertSee('Notice for my building')
        ->assertDontSee('Notice for a different building');
});

test('a tenant without an assigned unit sees no announcements', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $tenant = User::factory()->create();

    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    Announcement::factory()->for($property)->for($landlord, 'author')->create(['content' => 'Notice for residents']);

    $tenant->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($tenant)->get(route('announcements'));

    $response
        ->assertOk()
        ->assertDontSee('Notice for residents')
        ->assertSee('Nothing here yet');
});

test('a landlord can delete an announcement they posted', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    $announcement = Announcement::factory()->for($property)->for($landlord, 'author')->create();

    $this->actingAs($landlord);

    Livewire::test('pages::announcements')
        ->call('confirmDelete', $announcement->id)
        ->call('delete')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
});

test('a tenant cannot delete an announcement', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);

    $property = Property::factory()->for($landlord->currentTeam)->create();
    $announcement = Announcement::factory()->for($property)->for($landlord, 'author')->create();

    $tenant->switchTeam($landlord->currentTeam);
    $this->actingAs($tenant);

    Livewire::test('pages::announcements')
        ->call('confirmDelete', $announcement->id)
        ->assertForbidden();

    $this->assertDatabaseHas('announcements', ['id' => $announcement->id]);
});

test('a landlord cannot delete an announcement from another team', function () {
    $landlord = User::factory()->create();

    $otherTeam = Team::factory()->create();
    $otherProperty = Property::factory()->for($otherTeam)->create();
    $announcement = Announcement::factory()->for($otherProperty)->create();

    $this->actingAs($landlord);

    $this->expectException(ModelNotFoundException::class);

    Livewire::test('pages::announcements')
        ->call('confirmDelete', $announcement->id);
});

test('a landlord only sees announcements from their own team', function () {
    $landlord = User::factory()->create();
    $property = Property::factory()->for($landlord->currentTeam)->create();
    Announcement::factory()->for($property)->for($landlord, 'author')->create(['content' => 'Notice from my team']);

    $otherTeam = Team::factory()->create();
    $otherProperty = Property::factory()->for($otherTeam)->create();
    Announcement::factory()->for($otherProperty)->create(['content' => 'Notice from another team']);

    $landlord->switchTeam($landlord->currentTeam);

    $response = $this->actingAs($landlord)->get(route('announcements'));

    $response
        ->assertOk()
        ->assertSee('Notice from my team')
        ->assertDontSee('Notice from another team');
});
