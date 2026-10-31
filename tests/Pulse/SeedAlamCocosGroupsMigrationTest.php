<?php

use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Pulse\Support\TeamFactory;

/**
 * The 25 Alam Cocos location names as they stand in production, so the
 * migration is exercised against the real data rather than a sketch.
 */
const ALAM_COCOS_LOCATION_NAMES = [
    'Alam Bunker', 'alam east', 'alam east - old', 'alam east 2', 'Alam Near Sump',
    'Alam New1', 'Alam new2', 'alam north', 'Alam north 2', 'alam north west', 'Location alam',
    'hexagro', 'Hexagro Bunker', 'hexagro kalam',
    'doctor land 1', 'doctor land 2', 'dr land tarpaulin',
    'kannappa gounder east', 'kannappa gounder west', 'kannappa gounder to alam pith drying',
    'Loganatha Gounder', 'Loganatha goundar',
    'karanampettai', 'Hen forms', 'hari 2',
];

function alamCocosMigration(): object
{
    return require database_path('migrations/2026_07_29_000002_seed_alam_cocos_pith_location_groups.php');
}

function makeAlamCocosTeam(string $name = 'Alam Cocos'): int
{
    $teamId = TeamFactory::create($name);
    // Direct assignment: Pest's proxy does not support appending in place.
    test()->createdTeamIds = array_merge(test()->createdTeamIds ?? [], [$teamId]);

    foreach (ALAM_COCOS_LOCATION_NAMES as $locationName) {
        PithLocation::create([
            'team_id' => $teamId,
            'name' => $locationName,
            'is_active' => true,
        ]);
    }

    return $teamId;
}

/**
 * This migration ran before names were capped at 20 characters, so the fixtures
 * use the original long names and need the column width of that moment. The
 * ALTER is DDL, which MySQL implicitly commits, so cleanup here is by hand.
 */
beforeEach(function () {
    Schema::table('pulse_pith_locations', function ($table) {
        $table->string('name', 50)->nullable(false)->change();
    });

    // A real 'Alam Cocos' row would make the migration target the wrong team.
    DB::table('teams')->where('name', 'Alam Cocos')->update(['name' => 'Alam Cocos (parked '.uniqid().')']);

    $this->createdTeamIds = [];
});

afterEach(function () {
    foreach ($this->createdTeamIds ?? [] as $teamId) {
        DB::table('pulse_pith_locations')->where('team_id', $teamId)->delete();
        DB::table('pulse_pith_location_groups')->where('team_id', $teamId)->delete();
        DB::table('team_pulse')->where('team_id', $teamId)->delete();
        DB::table('teams')->where('id', $teamId)->delete();
    }

    Schema::table('pulse_pith_locations', function ($table) {
        $table->string('name', 20)->nullable(false)->change();
    });
});

it('creates the eight reviewed groups with the agreed counts', function () {
    $teamId = makeAlamCocosTeam();

    alamCocosMigration()->up();

    $counts = PithLocationGroup::where('team_id', $teamId)
        ->withCount('pithLocations')
        ->orderBy('display_order')
        ->pluck('pith_locations_count', 'name')
        ->all();

    expect($counts)->toBe([
        'Alam' => 11,
        'Hexagro' => 3,
        'Doctor Land' => 3,
        'Kannappa Gounder' => 3,
        'Loganatha Gounder' => 2,
        'Karanampettai' => 1,
        'Hen Forms' => 1,
        'Hari' => 1,
    ]);

    expect(PithLocation::where('team_id', $teamId)->whereNull('group_id')->count())->toBe(0);
});

it('keeps hexagro kalam out of the alam group', function () {
    $teamId = makeAlamCocosTeam();

    alamCocosMigration()->up();

    $location = PithLocation::where('team_id', $teamId)->where('name', 'hexagro kalam')->first();

    expect($location->group->name)->toBe('Hexagro');
});

it('is idempotent — running it twice changes nothing', function () {
    $teamId = makeAlamCocosTeam();

    $migration = alamCocosMigration();
    $migration->up();

    $before = PithLocation::where('team_id', $teamId)->pluck('group_id', 'id')->all();

    $migration->up();

    expect(PithLocationGroup::where('team_id', $teamId)->count())->toBe(8);
    expect(PithLocation::where('team_id', $teamId)->pluck('group_id', 'id')->all())->toBe($before);
});

it('never overwrites a location that was grouped by hand', function () {
    $teamId = makeAlamCocosTeam();
    $manual = PithLocationGroup::create(['team_id' => $teamId, 'name' => 'Manual Choice']);

    $location = PithLocation::where('team_id', $teamId)->where('name', 'alam east')->first();
    $location->update(['group_id' => $manual->id]);

    alamCocosMigration()->up();

    expect($location->fresh()->group_id)->toBe($manual->id);
    expect(PithLocationGroup::where('team_id', $teamId)->where('name', 'Alam')->first()->pithLocations()->count())->toBe(10);
});

it('reuses a group that already exists instead of duplicating it', function () {
    $teamId = makeAlamCocosTeam();
    PithLocationGroup::create(['team_id' => $teamId, 'name' => 'Alam', 'display_order' => 9]);

    alamCocosMigration()->up();

    $groups = PithLocationGroup::where('team_id', $teamId)->where('name', 'Alam')->get();

    expect($groups)->toHaveCount(1);
    expect($groups->first()->display_order)->toBe(9);
    expect($groups->first()->pithLocations()->count())->toBe(11);
});

it('releases every location back to ungrouped on rollback', function () {
    $teamId = makeAlamCocosTeam();

    $migration = alamCocosMigration();
    $migration->up();
    $migration->down();

    expect(PithLocationGroup::where('team_id', $teamId)->count())->toBe(0);
    expect(PithLocation::where('team_id', $teamId)->whereNull('group_id')->count())->toBe(25);
});

it('does nothing when no team carries the Alam Cocos name', function () {
    $teamId = makeAlamCocosTeam('Some Other Factory');

    alamCocosMigration()->up();

    expect(PithLocationGroup::where('team_id', $teamId)->count())->toBe(0);
    expect(PithLocation::where('team_id', $teamId)->whereNull('group_id')->count())->toBe(25);
});

it('leaves other factories untouched', function () {
    $teamId = makeAlamCocosTeam();
    $otherTeamId = makeAlamCocosTeam('Ramesh Coirs');

    alamCocosMigration()->up();

    expect(PithLocationGroup::where('team_id', $otherTeamId)->count())->toBe(0);
    expect(PithLocation::where('team_id', $otherTeamId)->whereNotNull('group_id')->count())->toBe(0);
    expect(PithLocation::where('team_id', $teamId)->whereNotNull('group_id')->count())->toBe(25);
});

it('leaves an unrecognised location ungrouped rather than guessing', function () {
    $teamId = makeAlamCocosTeam();
    PithLocation::create(['team_id' => $teamId, 'name' => 'Brand New Yard', 'is_active' => true]);

    alamCocosMigration()->up();

    $location = PithLocation::where('team_id', $teamId)->where('name', 'Brand New Yard')->first();

    expect($location->group_id)->toBeNull();
});
