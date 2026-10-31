<?php

use App\Models\Pulse\Shift;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * Summaries closed before the carry-over existed are short by whatever their machines made
 * while the uploads were failing overnight. The backfill re-runs the corrected processing
 * over those days — and, because the day methods stamp `shift_id` back onto raw readings as
 * well as writing the summary, a dry run has to leave both exactly as it found them.
 */
function backfillFibreTeam(string $anchor = '07:00:00'): array
{
    $teamId = TeamFactory::create('Backfill Factory '.uniqid(), false, [
        'modules' => [
            'pith' => ['is_active' => false, 'submodules' => ['blocks' => false, 'bales' => false]],
            'fibre' => ['is_active' => true, 'submodules' => ['weight' => true]],
        ],
    ]);

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId, 'module' => 'shifts', 'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor', 'value' => $anchor,
    ]);
    Shift::forgetAnchorCache();

    $deviceId = 940000 + random_int(1, 9999);
    DB::table('pulse_devices')->insert([
        'team_id' => $teamId, 'device_id' => $deviceId, 'module' => 'fibre', 'status' => 'active',
    ]);

    $day = Shift::create(['team_id' => $teamId, 'name' => 'Day', 'start_time' => '07:00:00', 'end_time' => '18:00:00', 'is_active' => 1]);
    $night = Shift::create(['team_id' => $teamId, 'name' => 'Night', 'start_time' => '18:00:00', 'end_time' => '07:00:00', 'is_active' => 1]);

    return [$teamId, $deviceId, $day, $night];
}

function backfillBale(int $deviceId, string $datetime, int $counter): void
{
    DB::table('pulse_device_data_fibre_bales')->insert([
        'device_id' => $deviceId,
        'bale_date' => substr($datetime, 0, 10),
        'bale_datetime' => $datetime,
        'bales_counter' => $counter,
        'eb_units_counter' => $counter,
        'is_duplicate' => 0,
        'shift_id' => null,
    ]);
}

function backfillFibreDay(): array
{
    [$teamId, $deviceId, $day, $night] = backfillFibreTeam();

    backfillBale($deviceId, '2026-08-09 21:00:00', 500);   // last reading of the previous shift day
    backfillBale($deviceId, '2026-08-10 07:30:00', 520);   // twenty bales made overnight
    backfillBale($deviceId, '2026-08-10 12:00:00', 530);

    // The row as the old logic left it: the overnight twenty counted as one.
    DB::table('pulse_summary_fibre_mega_bales')->insert([
        'team_id' => $teamId, 'device_id' => $deviceId, 'shift_date' => '2026-08-10',
        'shift_id' => $day->id, 'shift_name' => $day->name,
        'bales_produced' => 11, 'bales_weighed' => 0, 'weight' => 0, 'eb_units' => 0, 'eb_price' => 0,
    ]);

    return [$teamId, $deviceId, $day, $night];
}

function backfillProduced(int $teamId): int
{
    return (int) DB::table('pulse_summary_fibre_mega_bales')
        ->where('team_id', $teamId)->where('shift_date', '2026-08-10')->sum('bales_produced');
}

beforeEach(function () {
    $this->travelTo('2026-08-11 09:00:00');
    Shift::forgetAnchorCache();
});

it('reports what a backfill would recover without writing any of it', function () {
    [$teamId, $deviceId] = backfillFibreDay();

    $this->artisan('devicedata:backfill-carryover', [
        '--team_ids' => [$teamId], '--modules' => ['fibre'], '--dates' => ['2026-08-10'], '--dry-run' => true,
    ])->expectsOutputToContain('Would recover')->assertSuccessful();

    // The summary is untouched...
    expect(backfillProduced($teamId))->toBe(11)
        // ...and so are the raw readings the recompute would have stamped on its way through.
        ->and(DB::table('pulse_device_data_fibre_bales')
            ->where('device_id', $deviceId)->whereNotNull('shift_id')->count())->toBe(0);
});

it('corrects a day that was closed short, and is safe to run twice', function () {
    [$teamId] = backfillFibreDay();

    $this->artisan('devicedata:backfill-carryover', [
        '--team_ids' => [$teamId], '--modules' => ['fibre'], '--dates' => ['2026-08-10'],
    ])->assertSuccessful();

    // Thirty: the twenty made overnight plus the ten made during the day.
    expect(backfillProduced($teamId))->toBe(30);

    $this->artisan('devicedata:backfill-carryover', [
        '--team_ids' => [$teamId], '--modules' => ['fibre'], '--dates' => ['2026-08-10'],
    ])->expectsOutputToContain('Nothing moved.')->assertSuccessful();

    expect(backfillProduced($teamId))->toBe(30);
});

it('leaves teams outside the given ids alone', function () {
    [$teamId] = backfillFibreDay();
    [$otherTeamId] = backfillFibreDay();

    $this->artisan('devicedata:backfill-carryover', [
        '--team_ids' => [$teamId], '--modules' => ['fibre'], '--dates' => ['2026-08-10'],
    ])->assertSuccessful();

    expect(backfillProduced($teamId))->toBe(30)
        ->and(backfillProduced($otherTeamId))->toBe(11);
});

it('rejects a module it does not know', function () {
    $this->artisan('devicedata:backfill-carryover', ['--modules' => ['pith'], '--dates' => ['2026-08-10']])
        ->expectsOutputToContain('Unknown module(s): pith')
        ->assertFailed();
});
