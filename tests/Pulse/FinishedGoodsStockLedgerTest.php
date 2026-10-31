<?php

use App\Livewire\Pulse\MiniBale\MiniBaleCreateAndEdit;
use App\Livewire\Pulse\MiniBale\MiniBaleProductionList;
use App\Livewire\Pulse\PithBlocks\PithBlocksCreateAndEdit;
use App\Livewire\Pulse\PithBlocks\PithBlocksProductionList;
use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\PithBlocksProduction;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * The finished-goods ledgers run opening + produced - sold + correction = closing,
 * chained day to day, so an edit anywhere has to re-settle every later day.
 */
function produceBales(int $teamId, string $date, int $quantity): MiniBaleProduction
{
    return MiniBaleProduction::create([
        'team_id' => $teamId,
        'shift_date' => $date,
        'start_time' => $date.' 08:00:00',
        'end_time' => $date.' 18:00:00',
        'bales_expected' => $quantity,
        'bales_produced' => 0,
    ]);
}

/**
 * @return array<string, array{produced: int, sold: int|null, closing: int}>
 */
function ledgerByDate(int $teamId): array
{
    return MiniBaleProduction::where('team_id', $teamId)
        ->orderBy('shift_date')
        ->get()
        ->mapWithKeys(fn ($row) => [Carbon::parse($row->shift_date)->toDateString() => [
            'produced' => $row->producedForStock(),
            'sold' => $row->sold === null ? null : (int) $row->sold,
            'closing' => (int) $row->bales_in_stock,
        ]])
        ->all();
}

beforeEach(function () {
    // Yesterday must be a closed shift day regardless of the CI runner's clock.
    $this->travelTo(Carbon::parse('2026-09-26 13:00:00'));
    $this->teamId = TeamFactory::create('Stock Ledger Test '.uniqid());
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
    app()->instance('current.team', Team::find($this->teamId));

    $this->shiftId = DB::table('pulse_shifts')->insertGetId([
        'team_id' => $this->teamId,
        'name' => 'Shift 1',
        'start_time' => '08:00:00',
        'end_time' => '18:00:00',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->staffId = DB::table('staffs')->insertGetId([
        'team_id' => $this->teamId,
        'staff_name' => 'R. Selvam',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function () {
    $this->travelBack();
});

it('accumulates production into a running balance', function () {
    produceBales($this->teamId, '2026-08-05', 94);
    produceBales($this->teamId, '2026-08-06', 112);
    produceBales($this->teamId, '2026-08-07', 97);

    expect(ledgerByDate($this->teamId))->toBe([
        '2026-08-05' => ['produced' => 94, 'sold' => null, 'closing' => 94],
        '2026-08-06' => ['produced' => 112, 'sold' => null, 'closing' => 206],
        '2026-08-07' => ['produced' => 97, 'sold' => null, 'closing' => 303],
    ]);
});

it('ripples a sale forward through every later day', function () {
    produceBales($this->teamId, '2026-08-05', 94);
    produceBales($this->teamId, '2026-08-06', 112);
    produceBales($this->teamId, '2026-08-07', 97);

    MiniBaleProduction::where('team_id', $this->teamId)
        ->whereDate('shift_date', '2026-08-05')
        ->update(['sold' => 60]);
    MiniBaleProduction::reconcileInStock($this->teamId, '2026-08-05');

    expect(ledgerByDate($this->teamId))->toBe([
        '2026-08-05' => ['produced' => 94, 'sold' => 60, 'closing' => 34],
        '2026-08-06' => ['produced' => 112, 'sold' => null, 'closing' => 146],
        '2026-08-07' => ['produced' => 97, 'sold' => null, 'closing' => 243],
    ]);
});

it('applies a signed stock-take correction', function () {
    produceBales($this->teamId, '2026-08-05', 94);
    produceBales($this->teamId, '2026-08-06', 112);

    MiniBaleProduction::where('team_id', $this->teamId)
        ->whereDate('shift_date', '2026-08-05')
        ->update(['correction' => -4, 'correction_comment' => 'stock-take']);
    MiniBaleProduction::reconcileInStock($this->teamId, '2026-08-05');

    expect(ledgerByDate($this->teamId))->toBe([
        '2026-08-05' => ['produced' => 94, 'sold' => null, 'closing' => 90],
        '2026-08-06' => ['produced' => 112, 'sold' => null, 'closing' => 202],
    ]);
});

it('records a sale on a day with no production at all', function () {
    produceBales($this->teamId, '2026-08-05', 94);

    MiniBaleProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-09',
        'start_time' => '2026-08-09 08:00:00',
        'end_time' => '2026-08-09 18:00:00',
        'bales_expected' => 0,
        'bales_produced' => 0,
        'sold' => 50,
    ]);

    expect(ledgerByDate($this->teamId))->toBe([
        '2026-08-05' => ['produced' => 94, 'sold' => null, 'closing' => 94],
        '2026-08-09' => ['produced' => 0, 'sold' => 50, 'closing' => 44],
    ]);
});

it('re-settles the ledger when a production record is edited', function () {
    produceBales($this->teamId, '2026-08-05', 94);
    $second = produceBales($this->teamId, '2026-08-06', 112);

    $second->update(['bales_produced' => 80]);

    // The entered actual wins over the estimate, and the later balance follows.
    expect(ledgerByDate($this->teamId))->toBe([
        '2026-08-05' => ['produced' => 94, 'sold' => null, 'closing' => 94],
        '2026-08-06' => ['produced' => 80, 'sold' => null, 'closing' => 174],
    ]);
});

it('re-settles the ledger when a production record is deleted', function () {
    produceBales($this->teamId, '2026-08-05', 94);
    $second = produceBales($this->teamId, '2026-08-06', 112);

    $second->delete();

    expect(MiniBaleProduction::where('team_id', $this->teamId)->whereDate('shift_date', '2026-08-06')->exists())->toBeFalse()
        ->and(MiniBaleProduction::closingStockAt($this->teamId, '2026-08-06'))->toBe(94);
});

it('keeps a manually opened day when production arrives later', function () {
    $row = MiniBaleProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-05',
        'start_time' => '2026-08-05 08:00:00',
        'end_time' => '2026-08-05 18:00:00',
        'bales_expected' => 0,
        'bales_produced' => 0,
        'sold' => 10,
        'correction' => 5,
    ]);

    $row->update(['bales_expected' => 94]);

    expect(ledgerByDate($this->teamId))->toBe([
        '2026-08-05' => ['produced' => 94, 'sold' => 10, 'closing' => 89],
    ]);
});

it('runs the same chain for pith blocks on the summary', function () {
    foreach ([['2026-08-05', 400], ['2026-08-06', 300]] as [$date, $quantity]) {
        PithBlocksProduction::create([
            'team_id' => $this->teamId,
            'shift_date' => $date,
            'start_time' => $date.' 08:00:00',
            'end_time' => $date.' 18:00:00',
            'blocks_expected' => $quantity,
            'blocks_produced' => 0,
        ]);
    }

    PithBlocksProduction::where('team_id', $this->teamId)
        ->whereDate('shift_date', '2026-08-05')
        ->update(['sold' => 250]);
    PithBlocksProduction::reconcileInStock($this->teamId, '2026-08-05');

    $byDate = PithBlocksProduction::where('team_id', $this->teamId)
        ->orderBy('shift_date')
        ->get()
        ->mapWithKeys(fn ($row) => [
            Carbon::parse($row->shift_date)->toDateString() => [
                'sold' => $row->sold === null ? null : (int) $row->sold,
                'closing' => (int) $row->blocks_in_stock,
            ],
        ])
        ->all();

    expect($byDate)->toBe([
        '2026-08-05' => ['sold' => 250, 'closing' => 150],
        '2026-08-06' => ['sold' => null, 'closing' => 450],
    ]);
});

it('ripples a pith blocks sale forward through later days', function () {
    foreach ([['2026-08-05', 400], ['2026-08-06', 300]] as [$date, $quantity]) {
        PithBlocksProduction::create([
            'team_id' => $this->teamId,
            'shift_date' => $date,
            'start_time' => $date.' 08:00:00',
            'end_time' => $date.' 18:00:00',
            'blocks_expected' => $quantity,
            'blocks_produced' => $quantity,
        ]);
    }

    $dayOne = PithBlocksProduction::where('team_id', $this->teamId)
        ->whereDate('shift_date', '2026-08-05')
        ->first();

    $dayOne->update(['sold' => 100]);

    expect((int) $dayOne->fresh()->blocks_in_stock)
        ->toBe(300)
        ->and((int) PithBlocksProduction::where('team_id', $this->teamId)->whereDate('shift_date', '2026-08-06')->value('blocks_in_stock'))
        ->toBe(600);
});

it('keeps production comments independent of correction_comment', function () {
    $yesterday = now()->subDay()->toDateString();

    $production = PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => $yesterday,
        'staff_id' => $this->staffId,
        'start_time' => $yesterday.' 08:00:00',
        'end_time' => $yesterday.' 18:00:00',
        'blocks_produced' => 200,
        'comments' => 'Day production note',
    ]);

    Livewire::test(PithBlocksCreateAndEdit::class, ['id' => $production->id])
        ->set('soldQuantity', 10)
        ->set('correctionType', 'add')
        ->set('correctionAmount', 5)
        ->set('correctionComment', 'Stock reason only')
        ->set('comments', 'Day production note')
        ->call('save');

    $production->refresh();

    expect($production->comments)->toBe('Day production note')
        ->and($production->correction_comment)->toBe('Stock reason only')
        ->and((int) $production->correction)->toBe(5)
        ->and((int) $production->blocks_in_stock)->toBe(195);
});

it('rejects pith blocks edits older than the edit window', function () {
    $policy = PithBlocksProduction::entryPolicyFor($this->teamId);
    $oldDate = Carbon::parse($policy->minShiftDate())->subDays(2)->toDateString();

    $production = PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => $oldDate,
        'staff_id' => $this->staffId,
        'start_time' => $oldDate.' 08:00:00',
        'end_time' => $oldDate.' 18:00:00',
        'blocks_produced' => 100,
    ]);

    Livewire::test(PithBlocksCreateAndEdit::class, ['id' => $production->id])
        ->assertRedirect(route('pith-blocks.index', ['team' => $this->teamId]));
});

it('does not let the IoT job overwrite blocks_in_stock via accumulate', function () {
    $date = now()->subDay()->toDateString();

    $production = PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => $date,
        'start_time' => $date.' 08:00:00',
        'end_time' => $date.' 18:00:00',
        'blocks_expected' => 100,
        'blocks_produced' => 100,
        'sold' => 25,
    ]);

    expect((int) $production->fresh()->blocks_in_stock)->toBe(75);

    $production->update(['blocks_expected' => 120]);

    expect((int) $production->fresh()->blocks_in_stock)->toBe(75)
        ->and((int) $production->fresh()->correction)->toBe(0);
});

it('records a sale through the production list dialog', function () {
    $yesterday = now()->subDay()->toDateString();
    $production = produceBales($this->teamId, $yesterday, 120);
    $production->update(['bales_produced' => 120]);

    Livewire::test(MiniBaleProductionList::class)
        ->call('openSoldDialog', $production->id)
        ->set('soldQuantity', 45)
        ->call('saveStockDialog');

    expect((int) $production->fresh()->bales_in_stock)->toBe(75);
});

it('stores a correction with its comment from the production list dialog', function () {
    $yesterday = now()->subDay()->toDateString();
    $production = produceBales($this->teamId, $yesterday, 120);
    $production->update(['bales_produced' => 120]);

    Livewire::test(MiniBaleProductionList::class)
        ->call('openCorrectionDialog', $production->id)
        ->set('correctionType', 'deduct')
        ->set('correctionAmount', 8)
        ->set('correctionComment', 'Damaged in the yard')
        ->call('saveStockDialog');

    $row = $production->fresh();

    expect((int) $row->correction)->toBe(-8)
        ->and($row->correction_comment)->toBe('Damaged in the yard')
        ->and((int) $row->bales_in_stock)->toBe(112);
});

it('requires a sold figure before saving stock from the list dialog', function () {
    $yesterday = now()->subDay()->toDateString();
    $production = produceBales($this->teamId, $yesterday, 120);
    $production->update(['bales_produced' => 120]);

    Livewire::test(MiniBaleProductionList::class)
        ->call('openSoldDialog', $production->id)
        ->set('soldQuantity', null)
        ->call('saveStockDialog')
        ->assertHasErrors(['soldQuantity' => 'required']);
});

it('records a sale when the production date is saved from the edit screen', function () {
    $firstDate = now()->subDays(2)->toDateString();
    $secondDate = now()->subDay()->toDateString();

    $first = produceBales($this->teamId, $firstDate, 94);
    $first->update(['bales_produced' => 94]);
    produceBales($this->teamId, $secondDate, 112)->update([
        'bales_produced' => 112,
    ]);

    Livewire::test(MiniBaleCreateAndEdit::class, ['id' => $first->id])
        ->set('date', $firstDate)
        ->assertSet('recordProduced', 94)
        ->set('soldQuantity', 30)
        ->call('save');

    expect((int) MiniBaleProduction::where('team_id', $this->teamId)->whereDate('shift_date', $firstDate)->value('bales_in_stock'))
        ->toBe(64);
});

it('does not show stock movement on create', function () {
    Livewire::test(MiniBaleCreateAndEdit::class)
        ->assertDontSee('Stock movement');
});

/**
 * The API takes the shift day as `date` and derives shift_date from it.
 *
 * @param  array<string, mixed>  $overrides
 */
function postBaleProduction($test, string $date, array $overrides = []): TestResponse
{
    return $test->postJson('/api/fibre/store', array_merge([
        'date' => $date,
        'start_time' => $date.' 08:00:00',
        'end_time' => $date.' 18:00:00',
        'staff_id' => $test->staffId,
        'bales_produced' => 40,
    ], $overrides));
}

it('settles the day on a stock figure submitted through the API', function () {
    $firstDate = now()->subDays(2)->toDateString();
    $secondDate = now()->subDay()->toDateString();

    produceBales($this->teamId, $firstDate, 94);

    postBaleProduction($this, $secondDate, ['bales_in_stock' => 100])->assertCreated();

    // The chain would have reached 134; the counted 100 becomes a -34 correction.
    $row = MiniBaleProduction::where('team_id', $this->teamId)->whereDate('shift_date', $secondDate)->first();

    expect((int) $row->bales_in_stock)->toBe(100)
        ->and((int) $row->correction)->toBe(-34)
        ->and($row->correction_comment)->toBe('Stock-take from production entry');
});

it('ignores an omitted stock field rather than counting it as zero', function () {
    $firstDate = now()->subDays(2)->toDateString();
    $secondDate = now()->subDay()->toDateString();

    produceBales($this->teamId, $firstDate, 94);

    postBaleProduction($this, $secondDate)->assertCreated();

    $row = MiniBaleProduction::where('team_id', $this->teamId)->whereDate('shift_date', $secondDate)->first();

    expect((int) $row->bales_in_stock)->toBe(134)
        ->and((int) $row->correction)->toBe(0);
});

it('applies an API stock-take onto the production row', function () {
    $date = now()->subDay()->toDateString();

    postBaleProduction($this, $date, ['bales_in_stock' => 100])->assertCreated();

    $production = MiniBaleProduction::where('team_id', $this->teamId)->first();

    expect((int) $production->bales_in_stock)->toBe(100)
        ->and((int) $production->correction)->toBe(60);
});

it('ignores the running balance the nightly job writes into production rows', function () {
    produceBales($this->teamId, '2026-08-05', 94);

    MiniBaleProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-06',
        'start_time' => '2026-08-06 08:00:00',
        'end_time' => '2026-08-06 18:00:00',
        'bales_expected' => 40,
        'bales_produced' => 0,
        'bales_in_stock' => 134,
    ]);

    $row = MiniBaleProduction::where('team_id', $this->teamId)->whereDate('shift_date', '2026-08-06')->first();

    expect((int) $row->bales_in_stock)->toBe(134)
        ->and((int) $row->correction)->toBe(0);
});

it('lets a later sale on the inventory screen move a stock-taken day', function () {
    produceBales($this->teamId, '2026-08-05', 94);
    MiniBaleProduction::recordStockTake($this->teamId, '2026-08-05', 80, 'Counted');

    MiniBaleProduction::where('team_id', $this->teamId)
        ->whereDate('shift_date', '2026-08-05')
        ->update(['sold' => 20]);
    MiniBaleProduction::reconcileInStock($this->teamId, '2026-08-05');

    $row = MiniBaleProduction::where('team_id', $this->teamId)->whereDate('shift_date', '2026-08-05')->first();

    expect((int) $row->correction)->toBe(-14)
        ->and((int) $row->sold)->toBe(20)
        ->and((int) $row->bales_in_stock)->toBe(60);
});

it('reports the ledger balance from the API list endpoint', function () {
    produceBales($this->teamId, '2026-08-05', 94);
    produceBales($this->teamId, '2026-08-06', 40);

    MiniBaleProduction::where('team_id', $this->teamId)
        ->whereDate('shift_date', '2026-08-05')
        ->update(['sold' => 60]);
    MiniBaleProduction::reconcileInStock($this->teamId, '2026-08-05');

    $format = config('app.date_format');

    $byDate = collect($this->getJson('/api/fibre')->assertOk()->json('productions.data'))
        ->mapWithKeys(fn ($row) => [$row['date'] => $row['bales_in_stock']]);

    // Each row carries its own day's closing balance, not a per-shift snapshot:
    // 94 produced less 60 sold, then 40 more produced the next day.
    expect($byDate[Carbon::parse('2026-08-05')->format($format)])->toBe(34)
        ->and($byDate[Carbon::parse('2026-08-06')->format($format)])->toBe(74);
});

it('sorts and filters the list stock column on the ledger balance', function () {
    produceBales($this->teamId, '2026-08-05', 100);
    produceBales($this->teamId, '2026-08-06', 50);

    // Sell the lot on the second day so its balance drops below the first day's.
    MiniBaleProduction::where('team_id', $this->teamId)
        ->whereDate('shift_date', '2026-08-06')
        ->update(['sold' => 140]);
    MiniBaleProduction::reconcileInStock($this->teamId, '2026-08-06');

    $ordered = Livewire::test(MiniBaleProductionList::class)
        ->call('sortBy', 'bales_in_stock')
        ->viewData('productions')
        ->pluck('shift_date')
        ->all();

    // Descending by balance: 100 on the 5th, 10 on the 6th.
    expect($ordered)->toBe(['2026-08-05', '2026-08-06']);
});

it('answers the closing balance as at a date with no ledger row', function () {
    produceBales($this->teamId, '2026-08-05', 94);

    // Nothing happened on the 9th, so the balance is still the 5th's closing figure.
    expect(MiniBaleProduction::closingStockAt($this->teamId, '2026-08-09'))->toBe(94)
        ->and(MiniBaleProduction::closingStockAt($this->teamId, '2026-08-01'))->toBe(0);
});

it('pre-fills sold from the summary on the list dialog', function () {
    $date = now()->subDay()->toDateString();

    $production = produceBales($this->teamId, $date, 94);
    $production->update(['bales_produced' => 94, 'sold' => 25]);

    Livewire::test(MiniBaleProductionList::class)
        ->call('openSoldDialog', $production->id)
        ->assertSet('soldQuantity', 25)
        ->assertDontSee('Stock movement');
});

it('updates block stock from the pith blocks edit screen', function () {
    $yesterday = now()->subDay()->toDateString();

    $production = PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => $yesterday,
        'staff_id' => $this->staffId,
        'start_time' => $yesterday.' 08:00:00',
        'end_time' => $yesterday.' 18:00:00',
        'blocks_expected' => 900,
        'blocks_produced' => 900,
    ]);

    Livewire::test(PithBlocksProductionList::class)
        ->call('openSoldDialog', $production->id)
        ->assertSet('stockDialogProductionId', $production->id)
        ->assertDontSee('Stock movement')
        ->assertSee('Sold')
        ->assertSee('Update sold quantity for this production date.')
        ->assertDontSee('Add or deduct a stock correction for this production date.')
        ->set('soldQuantity', 400)
        ->call('saveStockDialog');

    expect((int) $production->fresh()->blocks_in_stock)->toBe(500)
        ->and(MiniBaleProduction::where('team_id', $this->teamId)->count())->toBe(0);
});

it('keeps the compact stock actions off the pith blocks create screen', function () {
    Livewire::test(PithBlocksCreateAndEdit::class)
        ->assertDontSee('Stock movement')
        ->assertDontSee('Daily ledger for this production date')
        ->assertDontSee('Correction Type');
});

it('keeps sold and correction controls out of the pith blocks edit form', function () {
    $yesterday = now()->subDay()->toDateString();

    $production = PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => $yesterday,
        'staff_id' => $this->staffId,
        'start_time' => $yesterday.' 08:00:00',
        'end_time' => $yesterday.' 18:00:00',
        'blocks_expected' => 112,
        'blocks_produced' => 112,
    ]);

    Livewire::test(PithBlocksCreateAndEdit::class, ['id' => $production->id])
        ->assertDontSee('Stock movement')
        ->assertDontSee('Daily ledger for this production date')
        ->assertDontSee('Correction Type');
});

it('stores block correction from the pith blocks table dialog', function () {
    $yesterday = now()->subDay()->toDateString();

    $production = PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => $yesterday,
        'staff_id' => $this->staffId,
        'start_time' => $yesterday.' 08:00:00',
        'end_time' => $yesterday.' 18:00:00',
        'blocks_expected' => 300,
        'blocks_produced' => 300,
    ]);

    Livewire::test(PithBlocksProductionList::class)
        ->call('openCorrectionDialog', $production->id)
        ->assertSet('stockDialogProductionId', $production->id)
        ->assertSee('Correction')
        ->assertSee('Add or deduct a stock correction for this production date.')
        ->assertDontSee('Update sold quantity for this production date.')
        ->set('correctionType', 'deduct')
        ->set('correctionAmount', 20)
        ->set('correctionComment', 'Damaged while loading')
        ->call('saveStockDialog');

    expect((int) $production->fresh()->correction)->toBe(-20)
        ->and($production->fresh()->correction_comment)->toBe('Damaged while loading')
        ->and((int) $production->fresh()->blocks_in_stock)->toBe(280);
});
