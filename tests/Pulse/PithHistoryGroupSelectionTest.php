<?php

use App\Livewire\Pulse\Pith\PithHistory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

function historyTeam(): int
{
    return TeamFactory::create('History Factory '.uniqid());
}

function historyLocation(int $teamId, string $name, ?int $groupId = null): PithLocation
{
    return PithLocation::create([
        'team_id' => $teamId,
        'group_id' => $groupId,
        'name' => $name,
        'is_active' => true,
    ]);
}

function historyLoad(int $locationId, string $date, float $inward, float $outward): void
{
    DB::table('pulse_pith_inventory')->insert([
        'pith_location_id' => $locationId,
        'date' => $date,
        'cft_offloaded' => $inward,
        'taken_cft_calculated' => $outward,
        'is_archived' => 0,
    ]);
}

function summaryRow(int $teamId, int $locationId, string $date, float $inward, float $outward): void
{
    DB::table('pulse_pith_inventory_summary')->insert([
        'summary_date' => $date,
        'team_id' => $teamId,
        'location_id' => $locationId,
        'inward' => $inward,
        'outward' => $outward,
        'in_stock' => max(0, $inward - $outward),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    // The history charts cap the current month at yesterday, so on the 1st the month holds no
    // completed day and every series is legitimately empty. These fixtures are written
    // relative to now(), so the clock is pinned mid-month rather than left to the calendar.
    $this->travelTo('2026-08-20 09:00:00');

    $this->teamId = historyTeam();
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
});

it('folds sidebar locations into their groups with ungrouped last', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Zulu Group']);
    historyLocation($this->teamId, 'grouped yard', $group->id);
    historyLocation($this->teamId, 'stray yard');

    $groups = collect(Livewire::test(PithHistory::class)->instance()->locationGroups());

    expect($groups)->toHaveCount(2);
    expect($groups->first()['name'])->toBe('Zulu Group');
    expect($groups->last()['name'])->toBe('Ungrouped');
});

it('selects every location in a group with one click', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $a = historyLocation($this->teamId, 'alam east', $group->id);
    $b = historyLocation($this->teamId, 'alam north', $group->id);
    historyLocation($this->teamId, 'stray yard');

    $component = Livewire::test(PithHistory::class)
        ->set('selectedLocations', [])
        ->call('toggleGroupSelection', $group->id);

    expect($component->get('selectedLocations'))
        ->toContain((string) $a->id)
        ->toContain((string) $b->id)
        ->toHaveCount(2);
});

it('clears a fully selected group on the second click', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    historyLocation($this->teamId, 'alam east', $group->id);
    historyLocation($this->teamId, 'alam north', $group->id);

    $component = Livewire::test(PithHistory::class)
        ->set('selectedLocations', [])
        ->call('toggleGroupSelection', $group->id);

    expect($component->get('selectedLocations'))->toHaveCount(2);

    $component->call('toggleGroupSelection', $group->id);
    expect($component->get('selectedLocations'))->toBe([]);
});

it('leaves other groups untouched when toggling one', function () {
    $alam = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $hexagro = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Hexagro']);
    historyLocation($this->teamId, 'alam east', $alam->id);
    $hex = historyLocation($this->teamId, 'hexagro bunker', $hexagro->id);

    $component = Livewire::test(PithHistory::class)
        ->set('selectedLocations', [(string) $hex->id])
        ->call('toggleGroupSelection', $alam->id);

    expect($component->get('selectedLocations'))->toContain((string) $hex->id);
    expect($component->get('selectedLocations'))->toHaveCount(2);
});

it('reports a partially selected group as not fully selected', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $a = historyLocation($this->teamId, 'alam east', $group->id);
    historyLocation($this->teamId, 'alam north', $group->id);

    $instance = Livewire::test(PithHistory::class)
        ->set('selectedLocations', [(string) $a->id])
        ->instance();

    $alam = collect($instance->locationGroups())->firstWhere('name', 'Alam');

    expect($alam['allSelected'])->toBeFalse();
    expect($alam['selectedCount'])->toBe(1);
});

it('plots each location of a ticked group as its own coloured line', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $a = historyLocation($this->teamId, 'alam east', $group->id);
    $b = historyLocation($this->teamId, 'alam north', $group->id);

    $day = now()->startOfMonth()->toDateString();
    summaryRow($this->teamId, $a->id, $day, 1000, 400);
    summaryRow($this->teamId, $b->id, $day, 500, 100);
    historyLoad($a->id, $day, 1000, 400);
    historyLoad($b->id, $day, 500, 100);

    $component = Livewire::test(PithHistory::class)
        ->set('selectedLocations', [])
        ->call('toggleGroupSelection', $group->id);

    $locations = collect($component->viewData('chart')['locations'])->keyBy('name');

    expect($locations['alam east']['color'])->toBe(PithHistory::PALETTE[0])
        ->and($locations['alam north']['color'])->toBe(PithHistory::PALETTE[1])
        ->and($locations['alam east']['stock'][0])->toBe(600.0)
        ->and($locations['alam east']['in'][0])->toBe(1000.0)
        ->and($locations['alam north']['out'][0])->toBe(100.0);
});

it('charts daily movements and watering for all locations, not running totals', function () {
    $a = historyLocation($this->teamId, 'alam east');
    $b = historyLocation($this->teamId, 'alam north');

    // The snapshot's inward column is a running total; the bars are each day's own movement.
    summaryRow($this->teamId, $a->id, '2026-08-03', 1000, 0);
    summaryRow($this->teamId, $a->id, '2026-08-04', 1000, 0);
    historyLoad($a->id, '2026-08-03', 1000, 0);
    historyLoad($b->id, '2026-08-04', 0, 250);
    DB::table('pulse_pith_waterings')->insert([
        ['pith_location_id' => $a->id, 'date' => '2026-08-04', 'litres' => 1200],
        ['pith_location_id' => $b->id, 'date' => '2026-08-04', 'litres' => 800],
    ]);

    $chart = Livewire::test(PithHistory::class)->viewData('chart');

    // Mid-month: the current month runs to yesterday.
    expect($chart['dates'])->toHaveCount(19)
        ->and($chart['daysInMonth'])->toBe(31)
        ->and($chart['aggregate']['in'][2])->toBe(1000.0)
        ->and($chart['aggregate']['in'][3])->toBe(0.0)
        ->and($chart['aggregate']['out'][3])->toBe(250.0)
        ->and($chart['aggregate']['stock'][3])->toBe(1000.0)
        ->and($chart['aggregate']['water'][3])->toBe(2000.0);
});

it('carries a location\'s stock across days the snapshot has not written', function () {
    $location = historyLocation($this->teamId, 'alam east');

    summaryRow($this->teamId, $location->id, '2026-07-30', 700, 0);
    summaryRow($this->teamId, $location->id, '2026-08-05', 900, 0);

    $stock = Livewire::test(PithHistory::class)->viewData('chart')['aggregate']['stock'];

    // 1–4 Aug carry July's last row, 5 Aug onward carries its own.
    expect($stock[0])->toBe(700.0)
        ->and($stock[3])->toBe(700.0)
        ->and($stock[4])->toBe(900.0)
        ->and($stock[18])->toBe(900.0);
});

it('steps between months, from the team\'s first month up to the current one', function () {
    DB::table('teams')->where('id', $this->teamId)->update(['created_at' => '2026-07-15 00:00:00']);

    Livewire::test(PithHistory::class)
        ->assertSet('selectedMonth', '2026-08')
        ->call('nextMonth')
        ->assertSet('selectedMonth', '2026-08')
        ->call('previousMonth')
        ->assertSet('selectedMonth', '2026-07')
        ->assertSee('Jul 2026')
        ->call('previousMonth')
        ->assertSet('selectedMonth', '2026-07');
});

it('ticks and unticks a single location', function () {
    $a = historyLocation($this->teamId, 'alam east');
    $b = historyLocation($this->teamId, 'alam north');

    Livewire::test(PithHistory::class)
        ->set('selectedLocations', [])
        ->call('toggleLocation', $b->id)
        ->call('toggleLocation', $a->id)
        ->assertSet('selectedLocations', [(string) $b->id, (string) $a->id])
        ->call('toggleLocation', $b->id)
        ->assertSet('selectedLocations', [(string) $a->id]);
});

it('names the selection after the group when exactly one group is fully selected', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Kannappa Gounder']);
    historyLocation($this->teamId, 'kg east', $group->id);
    historyLocation($this->teamId, 'kg west', $group->id);

    $component = Livewire::test(PithHistory::class)
        ->set('selectedLocations', [])
        ->call('toggleGroupSelection', $group->id);

    expect($component->instance()->selectionLabel())->toBe('Kannappa Gounder');
});

it('falls back to a location count when the selection spans groups', function () {
    $alam = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $hexagro = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Hexagro']);
    $a = historyLocation($this->teamId, 'alam east', $alam->id);
    $b = historyLocation($this->teamId, 'hexagro bunker', $hexagro->id);

    $instance = Livewire::test(PithHistory::class)
        ->set('selectedLocations', [(string) $a->id, (string) $b->id])
        ->instance();

    expect($instance->selectionLabel())->toBe('2 locations');
});

it('clears every selected location', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    historyLocation($this->teamId, 'alam east', $group->id);

    $component = Livewire::test(PithHistory::class)->call('clearSelectedLocations');

    expect($component->get('selectedLocations'))->toBe([]);
    expect($component->instance()->selectionLabel())->toBe('No locations selected');
});

it('opens the sidebar on the group holding the default selection', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    historyLocation($this->teamId, 'alam east', $group->id);

    // 'alam east' sorts first, so it is the default selection and its group opens.
    Livewire::test(PithHistory::class)->assertSet('expandedGroups', [$group->id]);
});

it('toggles a sidebar group open and closed without changing the selection', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    historyLocation($this->teamId, 'alam east', $group->id);

    $component = Livewire::test(PithHistory::class);
    $selectionBefore = $component->get('selectedLocations');

    $component->call('toggleGroupExpanded', $group->id);
    expect($component->get('expandedGroups'))->not->toContain($group->id);
    expect($component->get('selectedLocations'))->toBe($selectionBefore);

    $component->call('toggleGroupExpanded', $group->id);
    expect($component->get('expandedGroups'))->toContain($group->id);
});
