<?php

use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Services\Pulse\PithInventoryExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * Covers the ledger in PithInventoryExportService::buildReport(). A location holds one batch
 * at a time, running from its previous closure to its own; each row counts on its date clamped
 * into its batch's window, so a location's balance on day D is the running total of its open
 * batch since the last closure on or before D:
 *
 *   opening = balance(start - 1 day), closing = balance(end)
 *   closing = opening + inward_in_range - outward_in_range - write_off_in_range
 *
 * write_off_in_range ("Settled") is the signed leftover the range's closures took off the
 * books: positive for stock cleared, negative for a batch outward had overdrawn.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Pith Export Ledger Factory '.uniqid());
    $this->service = new PithInventoryExportService;
    $this->rangeStart = Carbon::parse('2026-02-01');
    $this->rangeEnd = Carbon::parse('2026-02-28');
});

/**
 * Records a closure the way PithProductionInventoryController::closeLocationStock() does:
 * the depletion row, then every archived row at the location not yet claimed by a closure
 * is linked to it.
 */
function insertDepletion(int $locationId, string $depletionDate, float $offloaded, float $taken): int
{
    $depletionId = DB::table('pulse_pith_stock_depletions')->insertGetId([
        'pith_location_id' => $locationId,
        'depletion_date' => $depletionDate,
        'total_cft_offloaded' => $offloaded,
        'total_cft_taken_calculated' => $taken,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pulse_pith_inventory')
        ->where('pith_location_id', $locationId)
        ->where('is_archived', 1)
        ->whereNull('pith_stock_depletion_id')
        ->update(['pith_stock_depletion_id' => $depletionId]);

    return $depletionId;
}

/**
 * Every row, subtotal and the grand total must satisfy Opening + In - Out - Settled = Closing.
 */
function expectReportReconciles(array $report): void
{
    $lines = collect($report['groups'])
        ->flatMap(fn ($group) => [...$group['locations'], $group['subtotal']])
        ->push($report['grand_total']);

    foreach ($lines as $line) {
        expect(round($line['opening_stock'] + $line['inward'] - $line['outward'] - $line['write_off_in_range'], 2))
            ->toBe($line['closing_stock']);
    }
}

/**
 * @return array<string, mixed>
 */
function locationRow(array $report, string $name): array
{
    return collect($report['groups'])
        ->flatMap(fn ($group) => $group['locations'])
        ->firstWhere('name', $name);
}

it('computes opening/inward/outward/closing correctly and reconciles group subtotals, flagging no-change locations rather than hiding them', function () {
    $groupB = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Group B', 'display_order' => 1]);
    $groupA = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Group A', 'display_order' => 2]);

    // Steady A: ordinary movement, no closures at all.
    $steadyA = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Steady A', 'group_id' => $groupA->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $steadyA->id, 'date' => '2026-01-10', 'cft_offloaded' => 100, 'taken_cft_calculated' => 0, 'is_archived' => 0]);
    PithInventory::create(['pith_location_id' => $steadyA->id, 'date' => '2026-02-05', 'cft_offloaded' => 50, 'taken_cft_calculated' => 20, 'is_archived' => 0]);

    // PreClosed B: closed BEFORE the range — opening must net to the post-closure balance (0), not the pre-closure 200.
    $preClosedB = PithLocation::create(['team_id' => $this->teamId, 'name' => 'PreClosed B', 'group_id' => $groupA->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $preClosedB->id, 'date' => '2026-01-05', 'cft_offloaded' => 200, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    insertDepletion($preClosedB->id, '2026-01-20', 200, 0);
    PithInventory::create(['pith_location_id' => $preClosedB->id, 'date' => '2026-02-10', 'cft_offloaded' => 40, 'taken_cft_calculated' => 10, 'is_archived' => 0]);

    // MidClose C: closed DURING the range — write_off_in_range must be non-zero and closing must reflect the write-off.
    $midCloseC = PithLocation::create(['team_id' => $this->teamId, 'name' => 'MidClose C', 'group_id' => $groupB->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $midCloseC->id, 'date' => '2026-02-05', 'cft_offloaded' => 80, 'taken_cft_calculated' => 30, 'is_archived' => 1]);
    insertDepletion($midCloseC->id, '2026-02-15', 80, 30);
    PithInventory::create(['pith_location_id' => $midCloseC->id, 'date' => '2026-02-20', 'cft_offloaded' => 15, 'taken_cft_calculated' => 5, 'is_archived' => 0]);

    // ZeroMove D: sitting on real stock the whole period, but nothing moved in range — excluded from the list, still counted in the group subtotal.
    $zeroMoveD = PithLocation::create(['team_id' => $this->teamId, 'name' => 'ZeroMove D', 'group_id' => $groupB->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $zeroMoveD->id, 'date' => '2026-01-01', 'cft_offloaded' => 60, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    // NoGroup E: ungrouped, should sort after both named groups.
    $noGroupE = PithLocation::create(['team_id' => $this->teamId, 'name' => 'NoGroup E', 'group_id' => null, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $noGroupE->id, 'date' => '2026-02-03', 'cft_offloaded' => 25, 'taken_cft_calculated' => 5, 'is_archived' => 0]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    expect($report['groups'])->toHaveCount(3);

    // Group B (display_order 1) sorts before Group A (display_order 2), ungrouped sorts last.
    expect($report['groups'][0]['name'])->toBe('Group B')
        ->and($report['groups'][1]['name'])->toBe('Group A')
        ->and($report['groups'][2]['is_ungrouped'])->toBeTrue();

    // Group B: both locations are listed — ZeroMove D stays visible but unflagged, MidClose C is flagged as moved.
    expect($report['groups'][0]['location_count'])->toBe(2);
    $groupBLocations = collect($report['groups'][0]['locations'])->keyBy('name');
    expect($groupBLocations)->toHaveCount(2);

    $zeroMove = $groupBLocations['ZeroMove D'];
    expect($zeroMove['has_movement'])->toBeFalse()
        ->and($zeroMove['opening_stock'])->toBe(60.0)
        ->and($zeroMove['closing_stock'])->toBe(60.0);

    $midClose = $groupBLocations['MidClose C'];
    expect($midClose['has_movement'])->toBeTrue()
        ->and($midClose['opening_stock'])->toBe(0.0)
        ->and($midClose['inward'])->toBe(95.0)
        ->and($midClose['outward'])->toBe(35.0)
        ->and($midClose['write_off_in_range'])->toBe(50.0)
        ->and($midClose['was_closed_in_range'])->toBeTrue()
        ->and($midClose['closing_stock'])->toBe(10.0);

    // Reconciliation identity holds even across the mid-range closure.
    expect($midClose['closing_stock'])->toBe(round(
        $midClose['opening_stock'] + $midClose['inward'] - $midClose['outward'] - $midClose['write_off_in_range'],
        2
    ));

    expect($report['groups'][0]['subtotal'])->toBe([
        'opening_stock' => 60.0, 'inward' => 95.0, 'outward' => 35.0, 'closing_stock' => 70.0, 'write_off_in_range' => 50.0, 'watered_litres' => 0,
    ]);

    // Group A: PreClosed B's opening correctly nets to 0 (the pre-range closure wrote off the 200).
    $groupALocations = collect($report['groups'][1]['locations'])->keyBy('name');
    expect($groupALocations)->toHaveCount(2);

    $preClosed = $groupALocations['PreClosed B'];
    expect($preClosed['opening_stock'])->toBe(0.0)
        ->and($preClosed['inward'])->toBe(40.0)
        ->and($preClosed['outward'])->toBe(10.0)
        ->and($preClosed['was_closed_in_range'])->toBeFalse()
        ->and($preClosed['closing_stock'])->toBe(30.0);

    $steady = $groupALocations['Steady A'];
    expect($steady['opening_stock'])->toBe(100.0)
        ->and($steady['inward'])->toBe(50.0)
        ->and($steady['outward'])->toBe(20.0)
        ->and($steady['closing_stock'])->toBe(130.0);

    expect($report['groups'][1]['subtotal'])->toBe([
        'opening_stock' => 100.0, 'inward' => 90.0, 'outward' => 30.0, 'closing_stock' => 160.0, 'write_off_in_range' => 0.0, 'watered_litres' => 0,
    ]);

    // Ungrouped.
    expect($report['groups'][2]['locations'])->toHaveCount(1);
    $noGroup = $report['groups'][2]['locations'][0];
    expect($noGroup['name'])->toBe('NoGroup E')
        ->and($noGroup['closing_stock'])->toBe(20.0);

    // Grand total sums every group's subtotal and itself reconciles.
    expect($report['grand_total'])->toBe([
        'opening_stock' => 160.0, 'inward' => 210.0, 'outward' => 70.0, 'closing_stock' => 250.0, 'write_off_in_range' => 50.0, 'watered_litres' => 0,
    ]);
});

it('still lists a group whose only location had no movement in range, unflagged, and counts its real stock in the grand total', function () {
    $quietGroup = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'All Quiet', 'display_order' => 1]);
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Untouched', 'group_id' => $quietGroup->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-01', 'cft_offloaded' => 60, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    expect($report['groups'])->toHaveCount(1);

    $group = $report['groups'][0];
    expect($group['name'])->toBe('All Quiet')
        ->and($group['location_count'])->toBe(1)
        ->and($group['locations'])->toHaveCount(1)
        ->and($group['locations'][0]['has_movement'])->toBeFalse()
        ->and($group['locations'][0]['opening_stock'])->toBe(60.0)
        ->and($group['locations'][0]['closing_stock'])->toBe(60.0);

    expect($report['grand_total'])->toBe([
        'opening_stock' => 60.0, 'inward' => 0.0, 'outward' => 0.0, 'closing_stock' => 60.0, 'write_off_in_range' => 0.0, 'watered_litres' => 0,
    ]);
});

it('drops deactivated locations that are empty and quiet, but keeps the ones still holding live loads or that moved in range', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Mixed', 'display_order' => 1]);

    // Retired: deactivated and emptied — nothing left to account for, so it must not appear.
    $retired = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Retired', 'group_id' => $group->id, 'is_active' => 0]);
    PithInventory::create(['pith_location_id' => $retired->id, 'date' => '2026-01-05', 'cft_offloaded' => 90, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    insertDepletion($retired->id, '2026-01-20', 90, 0);

    // Deactivated but never emptied — its stock is real, so it stays on the report.
    $stillStocked = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Still Stocked', 'group_id' => $group->id, 'is_active' => 0]);
    PithInventory::create(['pith_location_id' => $stillStocked->id, 'date' => '2026-01-05', 'cft_offloaded' => 70, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    // Deactivated and emptied, but stock moved through it inside the range — hiding it would
    // lose that movement from the totals.
    $movedThenRetired = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Moved Then Retired', 'group_id' => $group->id, 'is_active' => 0]);
    PithInventory::create(['pith_location_id' => $movedThenRetired->id, 'date' => '2026-02-10', 'cft_offloaded' => 40, 'taken_cft_calculated' => 40, 'is_archived' => 1]);
    insertDepletion($movedThenRetired->id, '2026-02-25', 40, 40);

    $active = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Active', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $active->id, 'date' => '2026-02-05', 'cft_offloaded' => 50, 'taken_cft_calculated' => 10, 'is_archived' => 0]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    expect($report['groups'])->toHaveCount(1);
    expect(collect($report['groups'][0]['locations'])->pluck('name')->all())
        ->toBe(['Active', 'Moved Then Retired', 'Still Stocked'])
        ->and($report['groups'][0]['location_count'])->toBe(3);

    // The dropped location contributed nothing, so rows and totals still agree.
    expect($report['grand_total'])->toBe([
        'opening_stock' => 70.0, 'inward' => 90.0, 'outward' => 50.0, 'closing_stock' => 110.0, 'write_off_in_range' => 0.0, 'watered_litres' => 0,
    ]);
});

it('omits a group entirely once every location in it has been deactivated and emptied', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'All Retired', 'display_order' => 1]);
    $retired = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Retired Only', 'group_id' => $group->id, 'is_active' => 0]);
    PithInventory::create(['pith_location_id' => $retired->id, 'date' => '2026-01-05', 'cft_offloaded' => 90, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    insertDepletion($retired->id, '2026-01-20', 90, 0);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    expect($report['groups'])->toBe([])
        ->and($report['grand_total']['closing_stock'])->toBe(0.0);
});

/**
 * Regression test for the phantom balances seen on the production Alam Cocos report
 * (Loganatha Gounder -37 cft, hexagro kalam -8, Hen Farms -4, hexagro +4). The
 * loads-to-CFT migration left pulse_pith_stock_depletions.total_cft_taken_calculated
 * disagreeing with the loads it closed on 17 of 27 rows, so a ledger that subtracted
 * the recorded write-off reported small non-zero balances — some negative — for
 * locations that had in fact been emptied. Balances anchor on the closure itself
 * rather than its stored amount, so the bad column can no longer leak into them.
 */
it('reports an emptied location as zero even when its closure snapshot disagrees with the loads it closed', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Skewed', 'display_order' => 1]);

    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Skewed Snapshot', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-10', 'cft_offloaded' => 300, 'taken_cft_calculated' => 262.64, 'is_archived' => 1]);
    // The closure recorded 225 taken against loads that actually netted 262.64 — a 37.36 cft
    // overstatement of the write-off, which used to surface as a -37 opening stock.
    insertDepletion($location->id, '2026-01-20', 300, 225);

    // A second location in the group keeps it on the report, so the skewed one can't be
    // hidden merely by its group disappearing.
    $neighbour = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Neighbour', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $neighbour->id, 'date' => '2026-02-04', 'cft_offloaded' => 300, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    // Emptied and quiet, so it reads as dormant and drops off the report entirely rather
    // than showing -37.
    expect(collect($report['groups'][0]['locations'])->pluck('name')->all())->toBe(['Neighbour']);
    expect($report['grand_total']['opening_stock'])->toBe(0.0)
        ->and($report['grand_total']['closing_stock'])->toBe(300.0);
});

it('hides dormant locations that held nothing at either end of the range and saw nothing move', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Quiet Mix', 'display_order' => 1]);

    // Never used at all.
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'Never Used', 'group_id' => $group->id, 'is_active' => 1]);

    // Used, then emptied well before the range — nothing to report on now.
    $emptied = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Emptied Earlier', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $emptied->id, 'date' => '2026-01-08', 'cft_offloaded' => 120, 'taken_cft_calculated' => 120, 'is_archived' => 1]);
    insertDepletion($emptied->id, '2026-01-09', 120, 120);

    // Empty at both ends, but stock passed through during the range — not dormant.
    $throughFlow = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Through Flow', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $throughFlow->id, 'date' => '2026-02-12', 'cft_offloaded' => 90, 'taken_cft_calculated' => 90, 'is_archived' => 0]);

    // Holding stock the whole time without moving — not dormant either.
    $holding = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Holding', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $holding->id, 'date' => '2026-01-03', 'cft_offloaded' => 45, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    expect(collect($report['groups'][0]['locations'])->pluck('name')->all())
        ->toBe(['Holding', 'Through Flow'])
        ->and($report['groups'][0]['location_count'])->toBe(2);

    expect($report['grand_total'])->toBe([
        'opening_stock' => 45.0, 'inward' => 90.0, 'outward' => 90.0, 'closing_stock' => 45.0, 'write_off_in_range' => 0.0, 'watered_litres' => 0,
    ]);
});

/**
 * The export and the Pith Inventory screen must not disagree about how much stock a
 * location holds. The screen uses PithLocation::remainingStockCft() (the net of the
 * non-archived loads), so a report ending today has to land on the same number — which is
 * precisely what the write-off-based ledger got wrong, by the size of the snapshot skew.
 */
it('closes a report ending today on the same stock figure the inventory screen shows', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Reconciled', 'display_order' => 1]);

    $reopened = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Reopened', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $reopened->id, 'date' => now()->subDays(30)->toDateString(), 'cft_offloaded' => 600, 'taken_cft_calculated' => 480.5, 'is_archived' => 1]);
    insertDepletion($reopened->id, now()->subDays(20)->toDateString(), 600, 465);
    PithInventory::create(['pith_location_id' => $reopened->id, 'date' => now()->subDays(5)->toDateString(), 'cft_offloaded' => 900, 'taken_cft_calculated' => 120.25, 'is_archived' => 0]);

    // No outward recorded yet, so taken_cft_calculated is NULL — it must still count as inward.
    $untouched = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Untouched Inward', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $untouched->id, 'date' => now()->subDays(3)->toDateString(), 'cft_offloaded' => 300, 'taken_cft_calculated' => null, 'is_archived' => 0]);

    $report = $this->service->buildReport($this->teamId, now()->subDays(40), now());

    $closingByName = collect($report['groups'][0]['locations'])->pluck('closing_stock', 'name');

    expect($closingByName['Reopened'])->toBe($reopened->remainingStockCft())
        ->and($closingByName['Reopened'])->toBe(779.75)
        ->and($closingByName['Untouched Inward'])->toBe($untouched->remainingStockCft())
        ->and($closingByName['Untouched Inward'])->toBe(300.0);
});

it('renders a subtotal row only for groups holding more than one location', function () {
    $solo = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Solo Group', 'display_order' => 1]);
    $pair = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Pair Group', 'display_order' => 2]);

    $only = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Only One', 'group_id' => $solo->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $only->id, 'date' => '2026-02-05', 'cft_offloaded' => 30, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    foreach (['Pair One', 'Pair Two'] as $name) {
        $location = PithLocation::create(['team_id' => $this->teamId, 'name' => $name, 'group_id' => $pair->id, 'is_active' => 1]);
        PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-05', 'cft_offloaded' => 30, 'taken_cft_calculated' => 0, 'is_archived' => 0]);
    }

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    $html = view('pulse::livewire.pith.exports.inventory-report', [
        'report' => $report,
        'teamName' => 'Test Team',
    ])->render();

    // One subtotal row in all: the pair's, not the solo group's.
    expect(substr_count($html, '<td>Subtotal</td>'))->toBe(1);
});

/**
 * Regression test for a real production bug: a location closed mid-range whose
 * write-off almost exactly cancels its opening stock can leave `closing_stock`
 * as floating-point negative zero (-0.0) rather than 0.0 — opening and
 * write-off are summed from independent queries/expressions, and their
 * results don't always land on bit-identical floats even when the location's
 * true balance is exactly zero. json_encode(-0.0) emits the literal "-0", but
 * JavaScript's JSON.stringify(-0) emits "0" — so once that value round-trips
 * through the browser, Livewire's snapshot checksum no longer matches what
 * the server recomputes, and every subsequent Livewire action in the export
 * modal fails with a 419 (CorruptComponentPayloadException / "page has
 * expired"). Exercised directly via reflection since the float residue that
 * triggers -0.0 depends on MySQL's internal FLOAT/DOUBLE summation order,
 * which isn't reliably reproducible by seeding rows through Eloquent.
 */
it('normalizes negative-zero totals so they do not corrupt the Livewire snapshot checksum', function () {
    $roundValue = new ReflectionMethod(PithInventoryExportService::class, 'roundValue');
    $roundValue->setAccessible(true);

    $negativeZero = $roundValue->invoke($this->service, -0.001);

    expect($negativeZero)->toBe(0.0)
        ->and(json_encode($negativeZero))->toBe('0');

    $roundTotals = new ReflectionMethod(PithInventoryExportService::class, 'roundTotals');
    $roundTotals->setAccessible(true);

    $totals = $roundTotals->invoke($this->service, [
        'opening_stock' => -0.0002,
        'inward' => 0.0,
        'outward' => 0.0,
        'closing_stock' => -0.0002,
        'write_off_in_range' => 0.0,
    ]);

    expect(json_encode($totals['opening_stock']))->toBe('0')
        ->and(json_encode($totals['closing_stock']))->toBe('0');
});

it('sums the water logged inside the range, whichever batch it belongs to', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Wet Yard', 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-20', 'cft_offloaded' => 100, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    $closedBatch = DB::table('pulse_pith_stock_depletions')->insertGetId([
        'pith_location_id' => $location->id, 'depletion_date' => '2026-02-15', 'total_cft_offloaded' => 0, 'total_cft_taken_calculated' => 0,
    ]);

    DB::table('pulse_pith_waterings')->insert([
        ['pith_location_id' => $location->id, 'pith_stock_depletion_id' => null, 'date' => '2026-01-31', 'litres' => 999],
        ['pith_location_id' => $location->id, 'pith_stock_depletion_id' => $closedBatch, 'date' => '2026-02-10', 'litres' => 1200],
        ['pith_location_id' => $location->id, 'pith_stock_depletion_id' => null, 'date' => '2026-02-20', 'litres' => 800],
        ['pith_location_id' => $location->id, 'pith_stock_depletion_id' => null, 'date' => '2026-03-01', 'litres' => 999],
    ]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    expect($report['groups'][0]['locations'][0]['watered_litres'])->toBe(2000)
        ->and($report['groups'][0]['subtotal']['watered_litres'])->toBe(2000)
        ->and($report['grand_total']['watered_litres'])->toBe(2000);
});

it('keeps a location that was only watered in range', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Only Watered', 'is_active' => 1]);
    DB::table('pulse_pith_waterings')->insert(['pith_location_id' => $location->id, 'date' => '2026-02-11', 'litres' => 300]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    expect(collect($report['groups'])->flatMap(fn ($group) => collect($group['locations'])->pluck('name'))->all())
        ->toBe(['Only Watered']);
});

it('lists dormant locations only when empty locations are included', function () {
    PithLocation::create(['team_id' => $this->teamId, 'name' => 'Never Used', 'is_active' => 1]);
    $retired = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Retired Empty', 'is_active' => 0]);
    PithInventory::create(['pith_location_id' => $retired->id, 'date' => '2026-01-08', 'cft_offloaded' => 50, 'taken_cft_calculated' => 50, 'is_archived' => 1]);

    $names = fn (array $report) => collect($report['groups'])->flatMap(fn ($group) => collect($group['locations'])->pluck('name'))->all();

    expect($names($this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd)))->toBe([])
        ->and($names($this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd, includeEmpty: true)))->toBe(['Never Used']);
});

it('shows the watering column only when asked', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Column Yard', 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-05', 'cft_offloaded' => 30, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);
    $render = fn (bool $showWatering) => view('pulse::livewire.pith.exports.inventory-report', [
        'report' => $report, 'teamName' => 'Test Team', 'showWatering' => $showWatering,
    ])->render();

    expect($render(true))->toContain('Watered (L)')
        ->and($render(false))->not->toContain('Watered (L)')
        ->and(view('pulse::livewire.pith.exports.inventory-report', ['report' => $report, 'teamName' => 'Test Team'])->render())
        ->not->toContain('Watered (L)');
});

it('counts inward added on the day a batch was closed in that day\'s closing, and settles the old batch there', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Same Day', 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-01', 'cft_offloaded' => 100, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-03', 'cft_offloaded' => 0, 'taken_cft_calculated' => 30, 'is_archived' => 1]);
    insertDepletion($location->id, '2026-02-10', 100, 30);

    // Re-stocked the same day, after the closure.
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-10', 'cft_offloaded' => 50, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    $day = $this->service->buildReport($this->teamId, Carbon::parse('2026-02-10'), Carbon::parse('2026-02-10'));
    expect(locationRow($day, 'Same Day'))->toMatchArray([
        'opening_stock' => 70.0, 'inward' => 50.0, 'outward' => 0.0, 'write_off_in_range' => 70.0, 'closing_stock' => 50.0,
    ]);

    $endingThatDay = $this->service->buildReport($this->teamId, Carbon::parse('2026-02-01'), Carbon::parse('2026-02-10'));
    expect(locationRow($endingThatDay, 'Same Day'))->toMatchArray([
        'opening_stock' => 0.0, 'inward' => 150.0, 'outward' => 30.0, 'write_off_in_range' => 70.0, 'closing_stock' => 50.0,
    ]);

    $after = $this->service->buildReport($this->teamId, Carbon::parse('2026-02-11'), Carbon::parse('2026-02-15'));
    expect(locationRow($after, 'Same Day')['opening_stock'])->toBe(50.0);

    // Still true once the re-stocked batch is itself closed later on.
    DB::table('pulse_pith_inventory')->where('pith_location_id', $location->id)->update(['is_archived' => 1]);
    insertDepletion($location->id, '2026-02-20', 50, 0);

    $dayAgain = $this->service->buildReport($this->teamId, Carbon::parse('2026-02-10'), Carbon::parse('2026-02-10'));
    expect(locationRow($dayAgain, 'Same Day')['closing_stock'])->toBe(50.0);

    foreach ([$day, $endingThatDay, $after, $dayAgain] as $report) {
        expectReportReconciles($report);
    }
});

it('opens every day on the running total since the location\'s last closure', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Running', 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-02', 'cft_offloaded' => 80, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-04', 'cft_offloaded' => 0, 'taken_cft_calculated' => 20, 'is_archived' => 1]);
    insertDepletion($location->id, '2026-02-06', 80, 20);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-08', 'cft_offloaded' => 40, 'taken_cft_calculated' => 0, 'is_archived' => 0]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-09', 'cft_offloaded' => 0, 'taken_cft_calculated' => 15, 'is_archived' => 0]);

    // Balance at the end of each day: batch 1 until its 6 Feb closure, batch 2 after.
    $expected = [
        '2026-02-01' => 0.0, '2026-02-02' => 80.0, '2026-02-04' => 60.0, '2026-02-05' => 60.0,
        '2026-02-06' => 0.0, '2026-02-07' => 0.0, '2026-02-08' => 40.0, '2026-02-09' => 25.0, '2026-02-12' => 25.0,
    ];

    foreach ($expected as $day => $balance) {
        $nextDay = Carbon::parse($day)->addDay();
        $report = $this->service->buildReport($this->teamId, $nextDay, $nextDay, includeEmpty: true);

        expect(locationRow($report, 'Running')['opening_stock'])->toBe($balance, "opening on {$nextDay->toDateString()}");
    }
});

/**
 * Locations merged on 2026-07-29 hold batches that ran side by side, and loads are
 * sometimes back-dated into a later batch. The old date-based rule dropped any load dated
 * on or before the latest closure, so a live batch's loads vanished from the balance.
 */
it('counts a later batch\'s loads dated before an earlier closure from that closure\'s day', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Merged', 'is_active' => 1]);

    $firstBatch = PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-05', 'cft_offloaded' => 100, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    $laterBatch = PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-06', 'cft_offloaded' => 300, 'taken_cft_calculated' => 0, 'is_archived' => 0]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-10', 'cft_offloaded' => 0, 'taken_cft_calculated' => 60, 'is_archived' => 1]);
    insertDepletion($location->id, '2026-01-20', 100, 60);

    // Still open at the end of February: counts from the 20 Jan closure onwards.
    $february = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);
    expect(locationRow($february, 'Merged'))->toMatchArray(['opening_stock' => 300.0, 'closing_stock' => 300.0]);

    // Closed in March: February still opens and closes on it.
    PithInventory::whereKey($laterBatch->id)->update(['is_archived' => 1]);
    insertDepletion($location->id, '2026-03-01', 300, 0);

    $february = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);
    expect(locationRow($february, 'Merged'))->toMatchArray(['opening_stock' => 300.0, 'closing_stock' => 300.0]);

    // January: the first batch settles its 40 cft on 20 Jan, the back-dated load lands that day.
    $january = $this->service->buildReport($this->teamId, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
    expect(locationRow($january, 'Merged'))->toMatchArray([
        'opening_stock' => 0.0, 'inward' => 400.0, 'outward' => 60.0, 'write_off_in_range' => 40.0, 'closing_stock' => 300.0,
    ]);

    expect($firstBatch->fresh()->pith_stock_depletion_id)->not->toBeNull();
    expectReportReconciles($january);
    expectReportReconciles($february);
});

it('keeps an overdrawn batch negative until its closure settles it', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Overdrawn', 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-10', 'cft_offloaded' => 100, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-02-05', 'cft_offloaded' => 0, 'taken_cft_calculated' => 150, 'is_archived' => 1]);
    insertDepletion($location->id, '2026-02-20', 100, 150);

    $report = $this->service->buildReport($this->teamId, Carbon::parse('2026-02-10'), $this->rangeEnd);
    $row = locationRow($report, 'Overdrawn');

    expect($row)->toMatchArray(['opening_stock' => -50.0, 'write_off_in_range' => -50.0, 'closing_stock' => 0.0, 'was_closed_in_range' => true])
        ->and($row['closures'])->toBe([
            ['date' => '2026-02-20', 'inward' => 100.0, 'outward' => 150.0, 'outward_ratio' => 150.0, 'settled' => -50.0],
        ]);

    $html = view('pulse::livewire.pith.exports.inventory-report', ['report' => $report, 'teamName' => 'Test Team'])->render();
    expect($html)->toContain('Closed 20 Feb · In 100 · Out 150 (150%) · 50 over-drawn');

    expectReportReconciles($report);
});

it('lists a closure that left nothing, with its batch ratio', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Even Close', 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-10', 'cft_offloaded' => 40, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    PithInventory::create(['pith_location_id' => $location->id, 'date' => '2026-01-12', 'cft_offloaded' => 0, 'taken_cft_calculated' => 40, 'is_archived' => 1]);
    insertDepletion($location->id, '2026-02-10', 40, 40);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);
    $row = locationRow($report, 'Even Close');

    expect($row['was_closed_in_range'])->toBeTrue()
        ->and($row['write_off_in_range'])->toBe(0.0)
        ->and($row['closures'][0])->toMatchArray(['outward_ratio' => 100.0, 'settled' => 0.0]);

    $html = view('pulse::livewire.pith.exports.inventory-report', ['report' => $report, 'teamName' => 'Test Team'])->render();
    expect($html)->toContain('Closed 10 Feb · In 40 · Out 40 (100%) · nothing left');
});

it('keeps a deactivated location closed in range so its opening stays in the totals', function () {
    $group = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'Retiring', 'display_order' => 1]);
    $retired = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Closed Then Retired', 'group_id' => $group->id, 'is_active' => 0]);
    PithInventory::create(['pith_location_id' => $retired->id, 'date' => '2026-01-10', 'cft_offloaded' => 278, 'taken_cft_calculated' => 0, 'is_archived' => 1]);
    insertDepletion($retired->id, '2026-02-03', 278, 0);

    $active = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Still Going', 'group_id' => $group->id, 'is_active' => 1]);
    PithInventory::create(['pith_location_id' => $active->id, 'date' => '2026-02-05', 'cft_offloaded' => 50, 'taken_cft_calculated' => 0, 'is_archived' => 0]);

    $report = $this->service->buildReport($this->teamId, $this->rangeStart, $this->rangeEnd);

    expect(locationRow($report, 'Closed Then Retired'))->toMatchArray(['opening_stock' => 278.0, 'write_off_in_range' => 278.0, 'closing_stock' => 0.0])
        ->and($report['grand_total'])->toMatchArray(['opening_stock' => 278.0, 'inward' => 50.0, 'write_off_in_range' => 278.0, 'closing_stock' => 50.0]);

    expectReportReconciles($report);
});
