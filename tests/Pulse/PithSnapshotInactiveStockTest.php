<?php

use App\Livewire\Pulse\Pith\PithHistory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

function snapshotTeam(): int
{
    return TeamFactory::create('Snapshot Factory '.uniqid());
}

function snapshotLocation(int $teamId, string $name, bool $active, ?int $groupId = null): PithLocation
{
    return PithLocation::create([
        'team_id' => $teamId,
        'group_id' => $groupId,
        'name' => $name,
        'is_active' => $active,
    ]);
}

function snapshotLoad(int $locationId, string $date, float $inward, float $outward = 0, bool $archived = false): void
{
    DB::table('pulse_pith_inventory')->insert([
        'pith_production_id' => null,
        'pith_location_id' => $locationId,
        'date' => $date,
        'cft_offloaded' => $inward,
        'taken_cft_calculated' => $outward,
        'is_archived' => $archived ? 1 : 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    // Pinned mid-month: the fixtures below are written relative to now(), and the history
    // charts cap the current month at yesterday — on the 1st there is no completed day to
    // draw and the assertions have nothing to read.
    $this->travelTo('2026-08-20 09:00:00');

    $this->teamId = snapshotTeam();
});

it('includes a deactivated location that still holds live stock', function () {
    $location = snapshotLocation($this->teamId, 'BED 4', active: false);
    snapshotLoad($location->id, now()->subDay()->toDateString(), 25500);

    $eligible = PithLocation::query()->activeOrHoldingStock()->pluck('pulse_pith_locations.id');

    expect($eligible)->toContain($location->id);
});

it('excludes a deactivated location whose loads have all been archived', function () {
    $location = snapshotLocation($this->teamId, 'emptied yard', active: false);
    snapshotLoad($location->id, now()->subDay()->toDateString(), 500, 500, archived: true);

    $eligible = PithLocation::query()->activeOrHoldingStock()->pluck('pulse_pith_locations.id');

    expect($eligible)->not->toContain($location->id);
});

it('excludes a deactivated location that never received stock', function () {
    $location = snapshotLocation($this->teamId, 'unused yard', active: false);

    $eligible = PithLocation::query()->activeOrHoldingStock()->pluck('pulse_pith_locations.id');

    expect($eligible)->not->toContain($location->id);
});

it('always includes an active location, stocked or not', function () {
    $empty = snapshotLocation($this->teamId, 'active empty', active: true);
    $stocked = snapshotLocation($this->teamId, 'active stocked', active: true);
    snapshotLoad($stocked->id, now()->toDateString(), 100);

    $eligible = PithLocation::query()->activeOrHoldingStock()->pluck('pulse_pith_locations.id');

    expect($eligible)->toContain($empty->id)->toContain($stocked->id);
});

it('writes snapshot rows for a deactivated location still holding stock', function () {
    $location = snapshotLocation($this->teamId, 'BED 4', active: false);
    snapshotLoad($location->id, now()->subDays(2)->toDateString(), 25500);

    expect(DB::table('pulse_pith_inventory_summary')->where('location_id', $location->id)->count())->toBe(0);

    $this->artisan('pith:inventory-snapshot')->assertExitCode(0);

    $rows = DB::table('pulse_pith_inventory_summary')
        ->where('location_id', $location->id)
        ->orderBy('summary_date')
        ->get();

    expect($rows)->not->toBeEmpty();
    expect((float) $rows->last()->in_stock)->toBe(25500.0);
});

it('still writes nothing for a deactivated location with no live stock', function () {
    $location = snapshotLocation($this->teamId, 'emptied yard', active: false);
    snapshotLoad($location->id, now()->subDays(2)->toDateString(), 500, 500, archived: true);

    $this->artisan('pith:inventory-snapshot')->assertExitCode(0);

    expect(DB::table('pulse_pith_inventory_summary')->where('location_id', $location->id)->count())->toBe(0);
});

it('counts a deactivated stocked location toward its group history', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);

    $active = snapshotLocation($this->teamId, 'alam east', active: true, groupId: $group->id);
    $retired = snapshotLocation($this->teamId, 'alam old', active: false, groupId: $group->id);

    $day = now()->subDays(2)->toDateString();
    snapshotLoad($active->id, $day, 1000, 400);
    snapshotLoad($retired->id, $day, 600, 100);

    $this->artisan('pith:inventory-snapshot')->assertExitCode(0);

    $user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($user);

    $component = Livewire::test(PithHistory::class)
        ->set('selectedLocations', [])
        ->call('toggleGroupSelection', $group->id);

    // Both locations are selectable, so the group series carries the retired stock too.
    expect($component->get('selectedLocations'))->toHaveCount(2);

    $chart = $component->viewData('chart');
    $dayIndex = array_search($day, $chart['dates'], true);

    // Each ticked location gets its own line, the retired yard included, and the
    // all-locations inward for that day counts both: 1000 active + 600 retired.
    expect(collect($chart['locations'])->pluck('name')->all())->toBe(['alam east', 'alam old'])
        ->and($chart['aggregate']['in'][$dayIndex])->toBe(1600.0);
});

it('lists a deactivated stocked location in the history sidebar', function () {
    $location = snapshotLocation($this->teamId, 'retired but stocked', active: false);
    snapshotLoad($location->id, now()->toDateString(), 900);

    $user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($user);

    Livewire::test(PithHistory::class)
        ->assertSee('retired but stocked')
        ->assertSee('Inactive');
});

it('keeps an emptied deactivated location out of the history sidebar', function () {
    snapshotLocation($this->teamId, 'active yard', active: true);
    $retired = snapshotLocation($this->teamId, 'emptied yard', active: false);
    snapshotLoad($retired->id, now()->toDateString(), 400, 400, archived: true);

    $user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($user);

    Livewire::test(PithHistory::class)->assertDontSee('emptied yard');
});
