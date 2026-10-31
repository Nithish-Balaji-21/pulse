<?php

use App\Models\Pulse\PithLocation;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

function mergeMigration(): object
{
    return require database_path('migrations/2026_07_29_000003_merge_duplicate_alam_cocos_pith_locations.php');
}

function mergeTeam(string $name = 'Alam Cocos'): int
{
    return TeamFactory::create($name);
}

function mergeLocation(int $teamId, string $name, bool $active = true): PithLocation
{
    return PithLocation::create(['team_id' => $teamId, 'name' => $name, 'is_active' => $active]);
}

function mergeLoad(int $locationId, float $inward, float $outward = 0, bool $archived = true, ?int $productionId = null): int
{
    return (int) DB::table('pulse_pith_inventory')->insertGetId([
        'pith_production_id' => $productionId,
        'pith_location_id' => $locationId,
        'date' => now()->subDay()->toDateString(),
        'cft_offloaded' => $inward,
        'taken_cft_calculated' => $outward,
        'is_archived' => $archived ? 1 : 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function mergeSummary(int $teamId, int $locationId, string $date, float $inward = 100, float $outward = 0): void
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
    DB::table('teams')->where('name', 'Alam Cocos')->update(['name' => 'Alam Cocos (parked '.uniqid().')']);
});

it('moves archived loads to the survivor and deletes the retired location', function () {
    $teamId = mergeTeam();
    $target = mergeLocation($teamId, 'alam east');
    $source = mergeLocation($teamId, 'alam east - old', active: false);

    mergeLoad($source->id, 500, 500);
    mergeLoad($source->id, 300, 300);
    mergeLoad($target->id, 1000, 200, archived: false);

    mergeMigration()->up();

    expect(PithLocation::find($source->id))->toBeNull();
    expect(DB::table('pulse_pith_inventory')->where('pith_location_id', $target->id)->count())->toBe(3);
    expect(DB::table('pulse_pith_inventory')->where('pith_location_id', $source->id)->count())->toBe(0);
});

it('leaves the survivor live stock untouched when only archived rows move', function () {
    $teamId = mergeTeam();
    $target = mergeLocation($teamId, 'alam east');
    $source = mergeLocation($teamId, 'alam east - old', active: false);

    mergeLoad($target->id, 14700, 1107.27, archived: false);
    mergeLoad($source->id, 9000, 9000);

    $before = $target->remainingStockCft();

    mergeMigration()->up();

    expect($target->fresh()->remainingStockCft())->toBe($before);
});

it('keeps the survivor summary history when no live rows move', function () {
    $teamId = mergeTeam();
    $target = mergeLocation($teamId, 'alam east');
    $source = mergeLocation($teamId, 'alam east - old', active: false);

    mergeSummary($teamId, $target->id, now()->subDays(2)->toDateString());
    mergeSummary($teamId, $target->id, now()->subDay()->toDateString());
    mergeLoad($source->id, 400, 400);

    mergeMigration()->up();

    expect(DB::table('pulse_pith_inventory_summary')->where('location_id', $target->id)->count())->toBe(2);
});

it('clears the survivor history when live rows move so the snapshot rebuilds it', function () {
    $teamId = mergeTeam();
    $target = mergeLocation($teamId, 'alam east');
    $source = mergeLocation($teamId, 'alam east - old', active: false);

    mergeSummary($teamId, $target->id, now()->subDays(2)->toDateString());
    // Non-archived but nets to zero, so the source is still "empty" and mergeable.
    mergeLoad($source->id, 600, 600, archived: false);

    mergeMigration()->up();

    expect(DB::table('pulse_pith_inventory_summary')->where('location_id', $target->id)->count())->toBe(0);
});

it('refuses to merge a source that still holds live stock', function () {
    $teamId = mergeTeam();
    $target = mergeLocation($teamId, 'Alam New1');
    $source = mergeLocation($teamId, 'Alam new2');

    mergeLoad($source->id, 5000, 0, archived: false);

    mergeMigration()->up();

    expect(PithLocation::find($source->id))->not->toBeNull();
    expect(DB::table('pulse_pith_inventory')->where('pith_location_id', $source->id)->count())->toBe(1);
});

it('moves depletion records across with the loads', function () {
    $teamId = mergeTeam();
    $target = mergeLocation($teamId, 'Loganatha Gounder');
    $source = mergeLocation($teamId, 'Loganatha goundar', active: false);

    $firstLoad = mergeLoad($source->id, 700, 700);
    DB::table('pulse_pith_stock_depletions')->insert([
        'pith_location_id' => $source->id,
        'depletion_date' => now()->subDay(),
        'first_pith_inventory_id' => $firstLoad,
        'last_pith_inventory_id' => $firstLoad,
        'total_cft_offloaded' => 700,
        'total_cft_taken_calculated' => 700,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    mergeMigration()->up();

    $depletion = DB::table('pulse_pith_stock_depletions')->where('first_pith_inventory_id', $firstLoad)->first();

    expect((int) $depletion->pith_location_id)->toBe($target->id);
});

it('drops summary rows belonging to the retired location', function () {
    $teamId = mergeTeam();
    mergeLocation($teamId, 'alam east');
    $source = mergeLocation($teamId, 'alam east - old', active: false);

    mergeSummary($teamId, $source->id, now()->subDay()->toDateString());
    mergeLoad($source->id, 100, 100);

    mergeMigration()->up();

    expect(DB::table('pulse_pith_inventory_summary')->where('location_id', $source->id)->count())->toBe(0);
});

it('keeps Alam north 2 and retires alam north', function () {
    $teamId = mergeTeam();
    $survivor = mergeLocation($teamId, 'Alam north 2');
    $retired = mergeLocation($teamId, 'alam north');

    mergeLoad($retired->id, 800, 800);

    mergeMigration()->up();

    expect(PithLocation::find($retired->id))->toBeNull();
    expect(PithLocation::find($survivor->id))->not->toBeNull();
    expect(DB::table('pulse_pith_inventory')->where('pith_location_id', $survivor->id)->count())->toBe(1);
});

it('keeps Alam New1 and retires Alam new2', function () {
    $teamId = mergeTeam();
    $survivor = mergeLocation($teamId, 'Alam New1');
    $retired = mergeLocation($teamId, 'Alam new2');

    mergeLoad($survivor->id, 14100, 0, archived: false);
    mergeLoad($retired->id, 200, 200);

    mergeMigration()->up();

    expect(PithLocation::find($retired->id))->toBeNull();
    expect($survivor->fresh()->remainingStockCft())->toBe(14100.0);
});

it('folds both retired locations into alam east', function () {
    $teamId = mergeTeam();
    $target = mergeLocation($teamId, 'alam east');
    $old = mergeLocation($teamId, 'alam east - old', active: false);
    $stray = mergeLocation($teamId, 'Location alam', active: false);

    mergeLoad($old->id, 100, 100);
    mergeLoad($stray->id, 200, 200);

    mergeMigration()->up();

    expect(PithLocation::find($old->id))->toBeNull();
    expect(PithLocation::find($stray->id))->toBeNull();
    expect(DB::table('pulse_pith_inventory')->where('pith_location_id', $target->id)->count())->toBe(2);
});

it('is safe to re-run once the merges are done', function () {
    $teamId = mergeTeam();
    $target = mergeLocation($teamId, 'alam east');
    $source = mergeLocation($teamId, 'alam east - old', active: false);
    mergeLoad($source->id, 100, 100);

    $migration = mergeMigration();
    $migration->up();
    $migration->up();

    expect(PithLocation::where('team_id', $teamId)->count())->toBe(1);
    expect(DB::table('pulse_pith_inventory')->where('pith_location_id', $target->id)->count())->toBe(1);
});

it('does nothing when the survivor does not exist', function () {
    $teamId = mergeTeam();
    $source = mergeLocation($teamId, 'alam east - old', active: false);

    mergeMigration()->up();

    expect(PithLocation::find($source->id))->not->toBeNull();
});

it('leaves other factories alone', function () {
    $teamId = mergeTeam();
    mergeLocation($teamId, 'alam east');
    mergeLocation($teamId, 'alam east - old', active: false);

    $otherTeamId = mergeTeam('Ramesh Coirs');
    $otherTarget = mergeLocation($otherTeamId, 'alam east');
    $otherSource = mergeLocation($otherTeamId, 'alam east - old', active: false);

    mergeMigration()->up();

    expect(PithLocation::find($otherSource->id))->not->toBeNull();
    expect(PithLocation::find($otherTarget->id))->not->toBeNull();
});
