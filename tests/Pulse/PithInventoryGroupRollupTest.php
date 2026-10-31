<?php

use App\Livewire\Pulse\Pith\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

function rollupTeam(): int
{
    return TeamFactory::create('Rollup Factory '.uniqid());
}

function stockAt(int $locationId, float $inward, float $outward = 0, bool $archived = false): void
{
    DB::table('pulse_pith_inventory')->insert([
        'pith_production_id' => null,
        'pith_location_id' => $locationId,
        'date' => now()->toDateString(),
        'cft_offloaded' => $inward,
        'taken_cft_calculated' => $outward,
        'is_archived' => $archived ? 1 : 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makeLocation(int $teamId, string $name, ?int $groupId = null, ?int $lowStock = null, bool $active = true): PithLocation
{
    return PithLocation::create([
        'team_id' => $teamId,
        'group_id' => $groupId,
        'name' => $name,
        'is_active' => $active,
        'low_stock_alert_value' => $lowStock,
    ]);
}

beforeEach(function () {
    $this->teamId = rollupTeam();
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
    session()->flush();
});

it('sums inward, outward and in-stock across a group', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);

    $a = makeLocation($this->teamId, 'alam east', $group->id);
    $b = makeLocation($this->teamId, 'alam north', $group->id);
    stockAt($a->id, 1000, 400);
    stockAt($b->id, 500, 100);

    $groups = Livewire::test(PithInventory::class)->viewData('groups');
    $alam = collect($groups)->firstWhere('name', 'Alam');

    expect($alam['totalOffloaded'])->toBe(1500.0);
    expect($alam['totalTaken'])->toBe(500.0);
    expect($alam['totalInStock'])->toBe(1000.0);
    expect($alam['locationCount'])->toBe(2);
});

it('counts low-stock and overdrawn children on the group header', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Hexagro']);

    $low = makeLocation($this->teamId, 'hexagro bunker', $group->id, lowStock: 500);
    stockAt($low->id, 1000, 800); // 200 remaining, under the 500 threshold

    $over = makeLocation($this->teamId, 'hexagro kalam', $group->id);
    stockAt($over->id, 300, 500); // taken exceeds offloaded

    $healthy = makeLocation($this->teamId, 'hexagro yard', $group->id, lowStock: 100);
    stockAt($healthy->id, 900, 100);

    $groups = Livewire::test(PithInventory::class)->viewData('groups');
    $hexagro = collect($groups)->firstWhere('name', 'Hexagro');

    expect($hexagro['lowCount'])->toBe(1);
    expect($hexagro['overdrawnCount'])->toBe(1);
    expect($hexagro['totalOverdrawn'])->toBe(200.0);
    // In-stock counts only positive remainders; the overdrawn location contributes nothing.
    expect($hexagro['totalInStock'])->toBe(1000.0);
});

it('collects locations without a group into an ungrouped bucket sorted last', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Zulu Group']);
    makeLocation($this->teamId, 'grouped yard', $group->id);
    makeLocation($this->teamId, 'stray yard');

    $groups = collect(Livewire::test(PithInventory::class)->viewData('groups'));

    expect($groups)->toHaveCount(2);
    expect($groups->last()['name'])->toBe('Ungrouped');
    expect($groups->last()['is_ungrouped'])->toBeTrue();
    expect($groups->first()['name'])->toBe('Zulu Group');
});

it('orders groups by display order before name', function () {
    $second = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Aaa Group', 'display_order' => 5]);
    $first = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Zzz Group', 'display_order' => 1]);

    makeLocation($this->teamId, 'yard one', $second->id);
    makeLocation($this->teamId, 'yard two', $first->id);

    $groups = collect(Livewire::test(PithInventory::class)->viewData('groups'));

    expect($groups->pluck('name')->all())->toBe(['Zzz Group', 'Aaa Group']);
});

it('falls back to a flat list when the team has no groups', function () {
    makeLocation($this->teamId, 'yard one');
    makeLocation($this->teamId, 'yard two');

    $component = Livewire::test(PithInventory::class);

    expect($component->viewData('hasGroups'))->toBeFalse();
    expect($component->viewData('groups'))->toHaveCount(1);
});

it('reports hasGroups once any location is assigned to a group', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    makeLocation($this->teamId, 'grouped yard', $group->id);

    expect(Livewire::test(PithInventory::class)->viewData('hasGroups'))->toBeTrue();
});

it('opens the ungrouped bucket by default and leaves real groups collapsed', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    makeLocation($this->teamId, 'grouped yard', $group->id);
    makeLocation($this->teamId, 'stray yard');

    Livewire::test(PithInventory::class)->assertSet('expandedGroups', [0]);
});

it('toggles a group open and closed', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    makeLocation($this->teamId, 'alam east', $group->id);

    $component = Livewire::test(PithInventory::class)->call('toggleGroup', $group->id);
    expect($component->get('expandedGroups'))->toContain($group->id);

    $component->call('toggleGroup', $group->id);
    expect($component->get('expandedGroups'))->not->toContain($group->id);
});

it('expands and collapses every group at once', function () {
    $one = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'One']);
    $two = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Two']);
    makeLocation($this->teamId, 'yard one', $one->id);
    makeLocation($this->teamId, 'yard two', $two->id);
    makeLocation($this->teamId, 'stray yard');

    $component = Livewire::test(PithInventory::class)->call('expandAllGroups');
    expect($component->get('expandedGroups'))->toHaveCount(3);

    $component->call('collapseAllGroups');
    expect($component->get('expandedGroups'))->toBe([]);
});

it('remembers the expand state across a remount', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    makeLocation($this->teamId, 'alam east', $group->id);

    Livewire::test(PithInventory::class)->call('toggleGroup', $group->id);

    expect(Livewire::test(PithInventory::class)->get('expandedGroups'))->toContain($group->id);
});

it('shows a location under its group header only while expanded', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $location = makeLocation($this->teamId, 'alam east yard', $group->id);
    stockAt($location->id, 1000, 200);

    Livewire::test(PithInventory::class)
        ->assertSee('Alam')
        ->assertDontSee('alam east yard')
        ->call('toggleGroup', $group->id)
        ->assertSee('alam east yard');
});

it('rolls watering up into each group and the page totals', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $a = makeLocation($this->teamId, 'wet east', $group->id);
    $b = makeLocation($this->teamId, 'wet west', $group->id);
    stockAt($a->id, 800, 300);
    stockAt($b->id, 400);

    $closed = DB::table('pulse_pith_stock_depletions')->insertGetId([
        'pith_location_id' => $a->id, 'depletion_date' => now()->subMonth()->toDateString(), 'total_cft_offloaded' => 0, 'total_cft_taken_calculated' => 0,
    ]);
    DB::table('pulse_pith_waterings')->insert([
        ['pith_location_id' => $a->id, 'pith_stock_depletion_id' => null, 'date' => now()->subDays(2)->toDateString(), 'litres' => 1500],
        ['pith_location_id' => $b->id, 'pith_stock_depletion_id' => null, 'date' => now()->subDay()->toDateString(), 'litres' => 700],
        ['pith_location_id' => $a->id, 'pith_stock_depletion_id' => $closed, 'date' => now()->subMonth()->toDateString(), 'litres' => 9999],
    ]);

    $component = Livewire::test(PithInventory::class);
    $rollup = collect($component->viewData('groups'))->firstWhere('name', 'Alam');

    expect($rollup['totalWaterLitres'])->toBe(2200)
        ->and($component->get('totalWaterLitres'))->toBe(2200)
        ->and($component->get('totalWaterCycles'))->toBe(2);
});

it('shows watered litres and the last watered day on an expanded location row', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $location = makeLocation($this->teamId, 'wet yard', $group->id);
    stockAt($location->id, 800, 300);
    DB::table('pulse_pith_waterings')->insert([
        ['pith_location_id' => $location->id, 'date' => '2026-09-10', 'litres' => 1500],
        ['pith_location_id' => $location->id, 'date' => '2026-09-18', 'litres' => 700],
    ]);

    Livewire::test(PithInventory::class)
        ->call('toggleGroup', $group->id)
        ->assertSee('2,200 L')
        ->assertSee('18 Sep');
});

it('opens a location passed in the query string, but only one of this team', function () {
    $mine = makeLocation($this->teamId, 'my yard');
    $other = makeLocation(rollupTeam(), 'their yard');

    Livewire::withQueryParams(['location' => $mine->id])->test(PithInventory::class)
        ->assertSet('selectedLocationId', $mine->id);

    Livewire::withQueryParams(['location' => $other->id])->test(PithInventory::class)
        ->assertSet('selectedLocationId', null);
});

it('refreshes its own factory\'s list even when the user\'s saved current team is another', function () {
    // Regression: after closing a batch the panel asks the list to refresh over a Livewire
    // update, which has no {team} in its URL. Resolving the team from users.current_team_id
    // there swapped in another factory's locations.
    $mine = makeLocation($this->teamId, 'my yard');
    stockAt($mine->id, 500);

    $otherTeam = rollupTeam();
    makeLocation($otherTeam, 'their yard');
    $this->user->forceFill(['current_team_id' => $otherTeam])->save();

    app()->instance('current.team', Team::find($this->teamId));
    $component = Livewire::test(PithInventory::class);
    app()->forgetInstance('current.team');

    $names = fn () => collect($component->get('locationsData'))->pluck('name')->all();

    expect($names())->toBe(['my yard']);

    $component->dispatch('pith-inventory-refresh');

    expect($names())->toBe(['my yard'])
        ->and($component->get('totalOffloaded'))->toEqual(500);
});

it('locks the team so the client cannot point the page at another factory', function () {
    Livewire::test(PithInventory::class)->set('teamId', rollupTeam());
})->throws(CannotUpdateLockedPropertyException::class);

it('toggles every group open, then closed, from one link', function () {
    $alam = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    makeLocation($this->teamId, 'alam east', $alam->id);

    Livewire::test(PithInventory::class)
        ->assertSee('Expand all')
        ->call('toggleAllGroups')
        ->assertSet('expandedGroups', [$alam->id])
        ->assertSee('Collapse all')
        ->call('toggleAllGroups')
        ->assertSet('expandedGroups', []);
});

it('leaves an emptied deactivated location out of the group entirely', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $active = makeLocation($this->teamId, 'active yard', $group->id);
    stockAt($active->id, 600, 100);

    // Deactivated with its loads archived: nothing left to show, so it is dropped
    // rather than rendered as an empty row.
    $retired = makeLocation($this->teamId, 'retired yard', $group->id, active: false);
    stockAt($retired->id, 300, 300, archived: true);

    $group = collect(Livewire::test(PithInventory::class)->viewData('groups'))->firstWhere('name', 'Alam');

    expect($group['locationCount'])->toBe(1);
});

it('still shows a deactivated location that holds stock', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $retired = makeLocation($this->teamId, 'retired but stocked', $group->id, active: false);
    stockAt($retired->id, 900, 100);

    $group = collect(Livewire::test(PithInventory::class)->viewData('groups'))->firstWhere('name', 'Alam');

    expect($group['locationCount'])->toBe(1);
    expect($group['totalInStock'])->toBe(800.0);
});
