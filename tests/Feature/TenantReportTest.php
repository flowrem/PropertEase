<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernStatus;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Models\Concern;
use App\Models\IssueType;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UnitItem;
use App\Models\User;
use App\Notifications\TenantReportSubmitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: User, 2: Lease}
 */
function reportingTenant(): array
{
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    $unit = Unit::factory()->for(Property::factory()->for($landlord->currentTeam))->create();
    $lease = Lease::factory()->for($unit)->create(['tenant_id' => $tenant->id, 'status' => LeaseStatus::Active]);

    return [$landlord, $tenant, $lease];
}

function issueType(string $name): IssueType
{
    return IssueType::query()->whereNull('team_id')->where('name', $name)->firstOrFail();
}

test('a tenant can report an issue with an item from their unit and a photo', function () {
    Notification::fake();
    Storage::fake(config('filesystems.sensitive_disk'));
    [$landlord, $tenant, $lease] = reportingTenant();
    $faucet = UnitItem::factory()->for($lease->unit)->create(['name' => 'Faucet, kitchen']);

    Livewire::actingAs($tenant)->test('pages::maintenance')
        ->set('issue_type_id', (string) issueType('Leaking faucet')->id)
        ->set('unit_item_id', (string) $faucet->id)
        ->set('priority', 'high')
        ->set('description', 'It keeps dripping even when fully closed.')
        ->set('photo', UploadedFile::fake()->image('faucet.jpg'))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('Leaking faucet: Faucet, kitchen');

    $concern = Concern::query()->sole();

    expect($concern->category)->toBe(ConcernCategory::Maintenance)
        ->and($concern->status)->toBe(ConcernStatus::Pending)
        ->and($concern->unit_item_id)->toBe($faucet->id)
        ->and($concern->lease_id)->toBe($lease->id);

    Storage::disk(config('filesystems.sensitive_disk'))->assertExists($concern->photo_path);
    Notification::assertSentTo($landlord, TenantReportSubmitted::class);
    Notification::assertNotSentTo($tenant, TenantReportSubmitted::class);
});

test('everyone on the landlord side of the team is told about a report, and nobody else', function () {
    Notification::fake();
    [$landlord, $tenant] = reportingTenant();
    $manager = User::factory()->create();
    $staff = User::factory()->create();
    $landlord->currentTeam->members()->attach($manager, ['role' => TeamRole::Admin]);
    $landlord->currentTeam->members()->attach($staff, ['role' => TeamRole::Member]);
    $otherLandlord = User::factory()->create();

    Livewire::actingAs($tenant)->test('pages::maintenance')
        ->set('issue_type_id', (string) issueType('No water')->id)
        ->set('description', 'No water from any tap since noon.')
        ->call('submit')
        ->assertHasNoErrors();

    Notification::assertSentTo([$landlord, $manager, $staff], TenantReportSubmitted::class);
    Notification::assertNotSentTo([$tenant, $otherLandlord], TenantReportSubmitted::class);
});

test('a tenant can only report items in their own unit', function () {
    [$landlord, $tenant, $lease] = reportingTenant();
    $otherUnit = Unit::factory()->for($lease->unit->property)->create();
    $otherItem = UnitItem::factory()->for($otherUnit)->create();
    $removedItem = UnitItem::factory()->for($lease->unit)->removed()->create();

    foreach ([$otherItem, $removedItem] as $item) {
        Livewire::actingAs($tenant)->test('pages::maintenance')
            ->set('issue_type_id', (string) issueType('Busted light')->id)
            ->set('unit_item_id', (string) $item->id)
            ->set('description', 'The light does not turn on.')
            ->call('submit')
            ->assertHasErrors(['unit_item_id']);
    }

    expect(Concern::query()->exists())->toBeFalse();
});

test('a tenant can only pick the platform issue types and their landlord\'s active ones', function () {
    [$landlord, $tenant] = reportingTenant();
    $own = IssueType::factory()->for($landlord->currentTeam)->create(['name' => 'Gate remote broken']);
    $inactive = IssueType::factory()->for($landlord->currentTeam)->inactive()->create();
    $otherTeams = IssueType::factory()->create();

    $page = Livewire::actingAs($tenant)->test('pages::maintenance')->assertSee('Gate remote broken');

    foreach ([$inactive, $otherTeams] as $issueType) {
        $page->set('issue_type_id', (string) $issueType->id)
            ->set('description', 'Something is wrong with it.')
            ->call('submit')
            ->assertHasErrors(['issue_type_id']);
    }

    $page->set('issue_type_id', (string) $own->id)->call('submit')->assertHasNoErrors();
});

test('someone without a lease on the team cannot send a report', function () {
    $landlord = User::factory()->create();
    $tenant = User::factory()->create();
    $landlord->currentTeam->members()->attach($tenant, ['role' => TeamRole::Tenant]);
    $tenant->switchTeam($landlord->currentTeam);

    Livewire::actingAs($tenant)->test('pages::maintenance')
        ->assertSee('You can report issues once you are renting a unit here.')
        ->set('issue_type_id', (string) issueType('Other')->id)
        ->set('description', 'Trying to report without a unit.')
        ->call('submit')
        ->assertForbidden();
});

test('a report photo is shown only to its tenant and the landlord\'s side of the team', function () {
    Storage::fake(config('filesystems.sensitive_disk'));
    [$landlord, $tenant, $lease] = reportingTenant();
    $path = UploadedFile::fake()->image('glass.jpg')->store("concerns/{$lease->id}", config('filesystems.sensitive_disk'));
    $concern = Concern::factory()->for($lease)->create(['photo_path' => $path]);
    $roommate = User::factory()->create();
    $landlord->currentTeam->members()->attach($roommate, ['role' => TeamRole::Tenant]);
    $roommate->switchTeam($landlord->currentTeam);

    $url = route('concerns.photo', ['current_team' => $landlord->currentTeam->slug, 'concern' => $concern->id]);

    $this->actingAs($tenant)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($landlord)->get($url)->assertOk();
    $this->actingAs($roommate)->get($url)->assertForbidden();
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
});
