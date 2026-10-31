<?php

use App\Http\Controllers\Pulse\PithLocationsController;
use App\Livewire\Pulse\ManagePithLocationGroups;
use App\Livewire\Pulse\ManagePithLocations;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

function pithGroupingTeam(): int
{
    return TeamFactory::create('Pith Grouping Factory '.uniqid());
}

/**
 * Give a location non-archived inward stock, optionally partly taken back out.
 */
function offloadToLocation(int $locationId, float $inward, float $outward = 0): void
{
    DB::table('pulse_pith_inventory')->insert([
        'pith_production_id' => null,
        'pith_location_id' => $locationId,
        'date' => now()->toDateString(),
        'cft_offloaded' => $inward,
        'taken_cft_calculated' => $outward,
        'is_archived' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->teamId = pithGroupingTeam();
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
});

it('creates a group and assigns a location to it', function () {
    Livewire::test(ManagePithLocationGroups::class)
        ->set('groupName', 'Alam')
        ->set('displayOrder', 1)
        ->call('store');

    $group = PithLocationGroup::where('team_id', $this->teamId)->first();
    expect($group)->not->toBeNull();
    expect($group->name)->toBe('Alam');
    expect($group->display_order)->toBe(1);

    Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'alam east')
        ->set('groupId', (string) $group->id)
        ->call('store');

    $location = PithLocation::where('team_id', $this->teamId)->where('name', 'alam east')->first();
    expect($location->group_id)->toBe($group->id);
});

it('offers every group of the team in the location form, including inactive ones', function () {
    PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Active Group']);
    PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Retired Group', 'is_active' => false]);

    Livewire::test(ManagePithLocations::class)
        ->call('openPithLocationModal')
        ->assertSee('Ungrouped')
        ->assertSee('Active Group')
        ->assertSee('Retired Group');
});

it('keeps a location ungrouped when no group is chosen', function () {
    Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'stray yard')
        ->call('store');

    $location = PithLocation::where('team_id', $this->teamId)->where('name', 'stray yard')->first();
    expect($location->group_id)->toBeNull();
});

it('ignores a group belonging to another team', function () {
    $otherTeamId = pithGroupingTeam();
    $foreignGroup = PithLocationGroup::create([
        'team_id' => $otherTeamId,
        'name' => 'Not Mine',
    ]);

    Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'borrowed yard')
        ->set('groupId', (string) $foreignGroup->id)
        ->call('store');

    $location = PithLocation::where('team_id', $this->teamId)->where('name', 'borrowed yard')->first();
    expect($location->group_id)->toBeNull();
});

it('refuses to deactivate a location that still holds stock', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'full yard',
        'is_active' => true,
    ]);
    offloadToLocation($location->id, 500);

    expect($location->remainingStockCft())->toBe(500.0);
    expect($location->hasRemainingStock())->toBeTrue();

    Livewire::test(ManagePithLocations::class)
        ->call('confirmToggle', $location->id)
        ->assertSet('confirmingToggle', false);

    expect((bool) $location->fresh()->is_active)->toBeTrue();
});

it('allows deactivating a location once its stock is fully taken out', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'emptied yard',
        'is_active' => true,
    ]);
    offloadToLocation($location->id, 500, 500);

    expect($location->remainingStockCft())->toBe(0.0);

    Livewire::test(ManagePithLocations::class)
        ->call('confirmToggle', $location->id)
        ->assertSet('confirmingToggle', true)
        ->call('toggleActive');

    expect((bool) $location->fresh()->is_active)->toBeFalse();
});

it('allows deactivating a location that never received stock', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'unused yard',
        'is_active' => true,
    ]);

    Livewire::test(ManagePithLocations::class)
        ->call('confirmToggle', $location->id)
        ->call('toggleActive');

    expect((bool) $location->fresh()->is_active)->toBeFalse();
});

it('always allows reactivating a location, even one holding stock', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'stocked but inactive',
        'is_active' => false,
    ]);
    offloadToLocation($location->id, 900);

    Livewire::test(ManagePithLocations::class)
        ->call('confirmToggle', $location->id)
        ->call('toggleActive');

    expect((bool) $location->fresh()->is_active)->toBeTrue();
});

it('blocks the deactivation guard at the controller even when the UI check is bypassed', function () {
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'guarded yard',
        'is_active' => true,
    ]);
    offloadToLocation($location->id, 250);

    $response = (new PithLocationsController)
        ->toggleActive($location->id, $this->teamId);

    expect($response->status())->toBe(422);
    expect((bool) $location->fresh()->is_active)->toBeTrue();
});

it('will not toggle a location owned by another team', function () {
    $otherTeamId = pithGroupingTeam();
    $foreign = PithLocation::create([
        'team_id' => $otherTeamId,
        'name' => 'foreign yard',
        'is_active' => true,
    ]);

    $response = (new PithLocationsController)
        ->toggleActive($foreign->id, $this->teamId);

    expect($response->status())->toBe(404);
    expect((bool) $foreign->fresh()->is_active)->toBeTrue();
});

it('refuses to deactivate a group that still has active locations', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Hexagro']);
    PithLocation::create([
        'team_id' => $this->teamId,
        'group_id' => $group->id,
        'name' => 'hexagro bunker',
        'is_active' => true,
    ]);

    Livewire::test(ManagePithLocationGroups::class)
        ->call('confirmToggle', $group->id)
        ->assertSet('confirmingToggle', false);

    expect((bool) $group->fresh()->is_active)->toBeTrue();
});

it('refuses to delete a group that still has locations assigned', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Doctor Land']);
    PithLocation::create([
        'team_id' => $this->teamId,
        'group_id' => $group->id,
        'name' => 'doctor land 1',
    ]);

    Livewire::test(ManagePithLocationGroups::class)->call('delete', $group->id);

    expect(PithLocationGroup::find($group->id))->not->toBeNull();
});

it('reports group name and remaining stock on the locations listing', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Kannappa Gounder']);
    $location = PithLocation::create([
        'team_id' => $this->teamId,
        'group_id' => $group->id,
        'name' => 'KG East',
        'is_active' => true,
    ]);
    offloadToLocation($location->id, 1000, 400);

    $rows = collect((new PithLocationsController)->index($this->teamId)->getData());
    $row = $rows->firstWhere('id', $location->id);

    expect($row->group_name)->toBe('Kannappa Gounder');
    expect((float) $row->remaining_cft)->toBe(600.0);
});
