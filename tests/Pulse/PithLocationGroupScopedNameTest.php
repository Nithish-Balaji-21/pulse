<?php

use App\Http\Controllers\Pulse\PithLocationsController;
use App\Livewire\Pulse\ManagePithLocations;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

function scopedTeam(): int
{
    return TeamFactory::create('Scoped Factory '.uniqid());
}

beforeEach(function () {
    $this->teamId = scopedTeam();
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);

    $this->alam = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Alam']);
    $this->hexagro = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Hexagro']);
});

it('allows the same name under two different groups', function () {
    PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    $response = (new PithLocationsController)->store(
        request()->merge(['name' => 'East', 'group_id' => $this->hexagro->id]),
        $this->teamId
    );

    expect($response->status())->toBe(201);
    expect(PithLocation::where('team_id', $this->teamId)->where('name', 'East')->count())->toBe(2);
});

it('still rejects the same name twice within one group', function () {
    PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    $response = (new PithLocationsController)->store(
        request()->merge(['name' => 'East', 'group_id' => $this->alam->id]),
        $this->teamId
    );

    expect($response->status())->toBe(422);
    expect(PithLocation::where('team_id', $this->teamId)->count())->toBe(1);
});

it('treats ungrouped locations as their own bucket', function () {
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'Stray']);

    $response = (new PithLocationsController)->store(
        request()->merge(['name' => 'Stray']),
        $this->teamId
    );

    expect($response->status())->toBe(422);
    expect(PithLocation::where('team_id', $this->teamId)->count())->toBe(1);
});

it('allows an ungrouped name that is already used inside a group', function () {
    PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    $response = (new PithLocationsController)->store(request()->merge(['name' => 'East']), $this->teamId);

    expect($response->status())->toBe(201);
});

it('blocks moving a location into a group that already has that name', function () {
    PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);
    $moving = PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->hexagro->id, 'name' => 'East']);

    $response = (new PithLocationsController)->update(
        request()->merge(['name' => 'East', 'group_id' => $this->alam->id]),
        $moving->id,
        $this->teamId
    );

    expect($response->status())->toBe(422);
    expect($moving->fresh()->group_id)->toBe($this->hexagro->id);
});

it('lets a location keep its own name when edited in place', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    $response = (new PithLocationsController)->update(
        request()->merge(['name' => 'East', 'group_id' => $this->alam->id, 'low_stock_alert_value' => 500]),
        $location->id,
        $this->teamId
    );

    expect($response->status())->toBe(200);
    expect($location->fresh()->low_stock_alert_value)->toBe(500);
});

it('enforces the database key even when validation is bypassed', function () {
    PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    expect(fn () => DB::table('pulse_pith_locations')->insert([
        'team_id' => $this->teamId,
        'group_id' => $this->alam->id,
        'name' => 'East',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces the database key for ungrouped duplicates too', function () {
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'Stray']);

    // Both rows have a NULL group_id, which MySQL would treat as distinct; the
    // group_key generated column collapses them to 0 so the key still catches it.
    expect(fn () => DB::table('pulse_pith_locations')->insert([
        'team_id' => $this->teamId,
        'group_id' => null,
        'name' => 'Stray',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('keeps group_key in step when a location moves between groups', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    $key = fn () => DB::table('pulse_pith_locations')->where('id', $location->id)->value('group_key');

    expect((int) $key())->toBe($this->alam->id);

    $location->update(['group_id' => $this->hexagro->id]);
    expect((int) $key())->toBe($this->hexagro->id);

    // Ungrouped collapses to 0 rather than NULL, which is what makes the key work.
    $location->update(['group_id' => null]);
    expect((int) $key())->toBe(0);
});

it('scopes the near-duplicate warning to the group', function () {
    PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    // Same-group near-duplicate is flagged.
    expect(PithLocationsController::findSimilarName('East 2', $this->teamId, null, $this->alam->id))->toBe('East');

    // The same name under another group is not a clash at all.
    expect(PithLocationsController::findSimilarName('East 2', $this->teamId, null, $this->hexagro->id))->toBeNull();
});

it('does not warn when adding the same name to a different group in the UI', function () {
    PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    $component = Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'East')
        ->set('groupId', (string) $this->hexagro->id)
        ->call('store');

    expect($component->get('similarNameWarning'))->toBeNull();
    expect(PithLocation::where('team_id', $this->teamId)->where('name', 'East')->count())->toBe(2);
});

it('clears an outstanding warning when the group is changed', function () {
    PithLocation::create(['team_id' => $this->teamId, 'group_id' => $this->alam->id, 'name' => 'East']);

    $component = Livewire::test(ManagePithLocations::class)
        ->set('pithLocationName', 'East 2')
        ->set('groupId', (string) $this->alam->id)
        ->call('store');

    expect($component->get('similarNameWarning'))->not->toBeNull();

    $component->set('groupId', (string) $this->hexagro->id);

    expect($component->get('similarNameWarning'))->toBeNull();
});
