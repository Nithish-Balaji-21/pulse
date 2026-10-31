<?php

use App\Models\Pulse\PithLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Pulse\Support\TeamFactory;

function shortenMigration(): object
{
    return require database_path('migrations/2026_07_29_000005_shorten_pith_location_name_column.php');
}

function shortenTeam(): int
{
    return TeamFactory::create('Shorten Factory '.uniqid());
}

/** Insert directly so fixtures are not blocked by the 20-char column. */
function longNameLocation(int $teamId, string $name): int
{
    return (int) DB::table('pulse_pith_locations')->insertGetId([
        'team_id' => $teamId,
        'name' => $name,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Altering the column is DDL, which MySQL implicitly commits — that ends the
 * transaction wrapping each test, so these hooks clean up by hand instead of
 * relying on a rollback that will not happen.
 */
beforeEach(function () {
    Schema::table('pulse_pith_locations', function ($table) {
        $table->string('name', 50)->nullable(false)->change();
    });

    // Any over-length row left behind would make the migration's global
    // "is everything short enough?" check non-deterministic.
    DB::table('pulse_pith_locations')->whereRaw('CHAR_LENGTH(name) > 20')->delete();

    $this->teamId = shortenTeam();
});

afterEach(function () {
    DB::table('pulse_pith_locations')->where('team_id', $this->teamId)->delete();
    DB::table('teams')->where('id', $this->teamId)->delete();

    Schema::table('pulse_pith_locations', function ($table) {
        $table->string('name', 50)->nullable(false)->change();
    });
});

it('shortens the Kannappa Gounder names', function () {
    $east = longNameLocation($this->teamId, 'kannappa gounder east');
    $west = longNameLocation($this->teamId, 'kannappa gounder west');
    $drying = longNameLocation($this->teamId, 'kannappa gounder to alam pith drying');

    shortenMigration()->up();

    expect(PithLocation::find($east)->name)->toBe('KG East');
    expect(PithLocation::find($west)->name)->toBe('KG West');
    expect(PithLocation::find($drying)->name)->toBe('KG to Alam Drying');
});

it('produces names that all fit the new limit', function () {
    foreach (array_keys(['kannappa gounder east' => 1, 'kannappa gounder west' => 1, 'kannappa gounder to alam pith drying' => 1]) as $name) {
        longNameLocation($this->teamId, $name);
    }

    shortenMigration()->up();

    $longest = DB::table('pulse_pith_locations')->where('team_id', $this->teamId)
        ->selectRaw('MAX(CHAR_LENGTH(name)) AS len')->value('len');

    expect((int) $longest)->toBeLessThanOrEqual(20);
});

it('shrinks the column once every name fits', function () {
    longNameLocation($this->teamId, 'kannappa gounder east');

    shortenMigration()->up();

    $type = DB::selectOne("SHOW COLUMNS FROM pulse_pith_locations WHERE Field = 'name'")->Type;

    expect(strtolower($type))->toBe('varchar(20)');
});

it('refuses to shrink while some other name is still too long', function () {
    longNameLocation($this->teamId, 'a name that is far too long to fit');

    shortenMigration()->up();

    $type = DB::selectOne("SHOW COLUMNS FROM pulse_pith_locations WHERE Field = 'name'")->Type;

    // Left at 50 rather than truncating a real name.
    expect(strtolower($type))->toBe('varchar(50)');
    expect(PithLocation::where('team_id', $this->teamId)->first()->name)->toBe('a name that is far too long to fit');
});

it('does not rename over a name the factory already uses', function () {
    longNameLocation($this->teamId, 'kannappa gounder east');
    longNameLocation($this->teamId, 'KG East');

    shortenMigration()->up();

    // The original keeps its name rather than colliding with the existing one.
    expect(PithLocation::where('team_id', $this->teamId)->where('name', 'kannappa gounder east')->exists())->toBeTrue();
});

it('is safe to re-run', function () {
    longNameLocation($this->teamId, 'kannappa gounder east');

    $migration = shortenMigration();
    $migration->up();
    $migration->up();

    expect(PithLocation::where('team_id', $this->teamId)->where('name', 'KG East')->count())->toBe(1);
});

it('leaves names under the limit untouched', function () {
    longNameLocation($this->teamId, 'Bunker');
    longNameLocation($this->teamId, 'Near Sump');

    shortenMigration()->up();

    expect(PithLocation::where('team_id', $this->teamId)->pluck('name')->all())
        ->toBe(['Bunker', 'Near Sump']);
});
