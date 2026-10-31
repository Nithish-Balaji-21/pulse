<?php

use App\Livewire\Pulse\MiniBale\MiniBaleProductionList;
use App\Livewire\Pulse\PithBlocks\PithBlocksProductionList;
use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\PithBlocksProduction;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * The production lists show the IoT estimate as the primary figure and treat a stored
 * 0 in bales_produced / blocks_produced as "not recorded" — those columns are never
 * written as NULL, so 0 is the only marker an unentered actual leaves behind.
 */

/**
 * @param  array<string, mixed>  $attributes
 */
function bale(int $teamId, string $date, array $attributes = []): MiniBaleProduction
{
    return MiniBaleProduction::create(array_merge([
        'team_id' => $teamId,
        'shift_date' => $date,
        'start_time' => $date.' 08:00:00',
        'end_time' => $date.' 18:00:00',
        'bales_expected' => 100,
        'bales_produced' => 0,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function block(int $teamId, string $date, array $attributes = []): PithBlocksProduction
{
    return PithBlocksProduction::create(array_merge([
        'team_id' => $teamId,
        'shift_date' => $date,
        'start_time' => $date.' 08:00:00',
        'end_time' => $date.' 18:00:00',
        'blocks_expected' => 400,
        'blocks_produced' => 0,
    ], $attributes));
}

beforeEach(function () {
    $this->teamId = TeamFactory::create('Sparse Table Test '.uniqid());
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
});

it('shows the estimate rather than a zero when no actual was entered', function () {
    bale($this->teamId, '2026-07-28', ['bales_expected' => 119]);

    Livewire::test(MiniBaleProductionList::class)
        ->assertSee('119')
        ->assertSee('est')
        ->assertDontSee('Estimated: 119');
});

it('drops columns the team has never filled in', function () {
    bale($this->teamId, '2026-07-28');

    Livewire::test(MiniBaleProductionList::class)
        ->assertSet('columns.staff', false)
        ->assertSet('columns.machine', false)
        ->assertSet('columns.comments', false)
        ->assertDontSee('COMMENTS');
});

it('keeps columns the team does fill in', function () {
    bale($this->teamId, '2026-07-28', ['comments' => 'Belt slipped']);

    Livewire::test(MiniBaleProductionList::class)
        ->assertSet('columns.comments', true)
        // Production opens a ledger row, so stock is always a meaningful column.
        ->assertSet('columns.stock', true)
        ->assertSee('COMMENTS')
        ->assertSee('IN STOCK');
});

it('keeps header, filter and body cells aligned with every optional column on', function () {
    $machineId = DB::table('pulse_master_minibale_machines')->insertGetId([
        'team_id' => $this->teamId,
        'machine_name' => 'Baler A',
        'device_id' => 0,
        'field_id' => 'd10',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $staffId = DB::table('staffs')->insertGetId([
        'team_id' => $this->teamId,
        'staff_name' => 'R. Selvam',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    bale($this->teamId, '2026-07-28', [
        'staff_id' => $staffId,
        'minibale_machine_id' => $machineId,
        'machine_name' => 'Baler A',
        'comments' => 'Belt slipped',
    ]);
    MiniBaleProduction::where('team_id', $this->teamId)->update(['bales_in_stock' => 40]);

    $html = Livewire::test(MiniBaleProductionList::class)
        ->assertSet('columns.staff', true)
        ->assertSet('columns.machine', true)
        ->assertSet('columns.stock', true)
        ->assertSet('columns.comments', true)
        ->html();

    preg_match('/<thead.*?<tr[^>]*>(.*?)<\/tr>/s', $html, $header);
    preg_match('/<tbody.*?<tr[^>]*>(.*?)<\/tr>/s', $html, $body);

    expect(substr_count($header[1], '<th'))->toBe(8)
        ->and(substr_count($body[1], '<td'))->toBe(8);
});

it('reports actuals coverage over the filtered set', function () {
    bale($this->teamId, '2026-07-26', ['bales_expected' => 100, 'bales_produced' => 90]);
    bale($this->teamId, '2026-07-27', ['bales_expected' => 50]);
    bale($this->teamId, '2026-07-28', ['bales_expected' => 30]);

    $totals = Livewire::test(MiniBaleProductionList::class)->viewData('totals');

    expect((int) $totals->shift_count)->toBe(3)
        ->and((int) $totals->estimated_total)->toBe(180)
        ->and((int) $totals->actual_total)->toBe(90)
        ->and((int) $totals->actual_shift_count)->toBe(1)
        // Variance compares against the estimate for the recorded shift only.
        ->and((int) $totals->estimated_comparable)->toBe(100);
});

it('shows the ledger closing balance against each shift', function () {
    bale($this->teamId, '2026-07-20', ['bales_expected' => 100]);
    bale($this->teamId, '2026-07-28', ['bales_expected' => 40]);

    $levels = Livewire::test(MiniBaleProductionList::class)->viewData('stockLevels');
    $rows = MiniBaleProduction::where('team_id', $this->teamId)->orderBy('shift_date')->get();

    // The balance runs forward: 100 on the 20th, 140 by the 28th.
    expect($levels[$rows[0]->id]['value'])->toBe(100.0)
        ->and($levels[$rows[1]->id]['value'])->toBe(140.0);
});

it('marks a day confirmed only once its movement was entered', function () {
    bale($this->teamId, '2026-07-20', ['bales_expected' => 100]);
    bale($this->teamId, '2026-07-28', ['bales_expected' => 40]);

    MiniBaleProduction::where('team_id', $this->teamId)
        ->whereDate('shift_date', '2026-07-20')
        ->update(['sold' => 60]);
    MiniBaleProduction::reconcileInStock($this->teamId, '2026-07-20');

    $levels = Livewire::test(MiniBaleProductionList::class)->viewData('stockLevels');
    $rows = MiniBaleProduction::where('team_id', $this->teamId)->orderBy('shift_date')->get();

    expect($levels[$rows[0]->id]['recorded'])->toBeTrue()
        ->and($levels[$rows[0]->id]['value'])->toBe(40.0)
        ->and($levels[$rows[1]->id]['recorded'])->toBeFalse()
        // The sale ripples forward: 40 carried in, plus 40 produced.
        ->and($levels[$rows[1]->id]['value'])->toBe(80.0);
});

it('scales the output bar to the heaviest shift on the page', function () {
    bale($this->teamId, '2026-07-27', ['bales_expected' => 30]);
    bale($this->teamId, '2026-07-28', ['bales_expected' => 119]);

    expect(Livewire::test(MiniBaleProductionList::class)->viewData('barScale'))->toBe(119.0);
});

it('sorts the output column on the value each row displays', function () {
    bale($this->teamId, '2026-07-26', ['bales_expected' => 200, 'bales_produced' => 10]);
    bale($this->teamId, '2026-07-27', ['bales_expected' => 90]);
    bale($this->teamId, '2026-07-28', ['bales_expected' => 5]);

    $ordered = Livewire::test(MiniBaleProductionList::class)
        ->call('sortBy', 'output')
        ->viewData('productions')
        ->pluck('shift_date')
        ->all();

    // Descending: the recorded 10 sorts below the 90 and 5 estimates it displays against.
    expect($ordered)->toBe(['2026-07-27', '2026-07-26', '2026-07-28']);
});

it('refuses an unmapped sort column instead of passing it to ORDER BY', function () {
    bale($this->teamId, '2026-07-28');

    Livewire::test(MiniBaleProductionList::class)
        ->call('sortBy', 'bales_produced) --')
        ->assertSet('sortColumn', 'shift_date')
        ->assertOk();

    Livewire::test(PithBlocksProductionList::class)
        ->call('sortBy', 'blocks_produced) --')
        ->assertSet('sortColumn', 'shift_date')
        ->assertOk();
});

it('matches unrecorded shifts when filtering produced by zero range', function () {
    bale($this->teamId, '2026-07-27', ['bales_produced' => 40]);
    bale($this->teamId, '2026-07-28', ['bales_produced' => 0]);

    $matched = Livewire::test(MiniBaleProductionList::class)
        ->set('draftOutMin', '0')
        ->set('draftOutMax', '0')
        ->call('applyFilters')
        ->viewData('productions');

    expect($matched->pluck('shift_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all())
        ->toBe(['2026-07-28']);
});

it('applies the same model to pith blocks', function () {
    block($this->teamId, '2026-07-27', ['blocks_expected' => 900]);
    block($this->teamId, '2026-07-28', ['blocks_expected' => 400, 'blocks_produced' => 380]);

    $test = Livewire::test(PithBlocksProductionList::class)
        ->assertSet('columns.staff', false)
        ->assertSet('columns.comments', false)
        ->assertSee('OUTPUT (BLOCKS)')
        ->assertSee('est');

    $totals = $test->viewData('totals');

    expect((int) $totals->actual_shift_count)->toBe(1)
        ->and((int) $totals->actual_total)->toBe(380)
        ->and($test->viewData('barScale'))->toBe(900.0);
});

it('never renders an actual when the team has actuals switched off', function () {
    bale($this->teamId, '2026-07-28', ['bales_expected' => 100, 'bales_produced' => 95]);

    DB::table('pulse_settings')->insert([
        'team_id' => $this->teamId,
        'module' => 'pith',
        'key' => 'SHOW_ACTUALS',
        'text' => 'Show actuals',
        'value' => 'no',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(MiniBaleProductionList::class)
        ->assertSet('showActuals', false)
        ->assertSee('100')
        ->assertDontSee('of 100 est');
});
