<?php

use App\Livewire\Pulse\Pith\PithLocationLoads;
use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithStockDepletion;
use App\Models\Pulse\PithWatering;
use App\Services\NotificationSenderService;
use App\Services\Pulse\PithLocationLedgerService;
use Livewire\Livewire;
use Tests\Pulse\Support\RbacFixtures;

/**
 * The location panel beside the Pith Inventory list: numbered batches, the entries ledger
 * (stock and watering), the Stock In / Stock Out / Watering form, delete, close, and closed
 * batches viewed read-only.
 */
beforeEach(function () {
    $this->travelTo('2026-09-23 10:00:00');
    $this->teamId = RbacFixtures::team('Pith Panel');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);

    $this->location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Alam Bunker', 'is_active' => 1]);

    $this->mock(NotificationSenderService::class, fn ($mock) => $mock->shouldReceive('notifyTeamUsers'));
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

function panelLoad(PithLocation $location, string $date, float $inward = 0, float $outward = 0, bool $archived = false, ?int $productionId = null): PithInventory
{
    return PithInventory::create([
        'pith_location_id' => $location->id,
        'pith_production_id' => $productionId,
        'date' => $date,
        'cft_offloaded' => $inward,
        'taken_cft_calculated' => $outward,
        'is_archived' => $archived ? 1 : 0,
    ]);
}

function panelWatering(PithLocation $location, string $date, int $litres, ?int $depletionId = null): PithWatering
{
    return PithWatering::create(['pith_location_id' => $location->id, 'pith_stock_depletion_id' => $depletionId, 'date' => $date, 'litres' => $litres]);
}

/**
 * A closed batch holding the given archived loads, as closeLocationStock records it.
 *
 * @param  list<PithInventory>  $loads
 */
function panelClosedBatch(PithLocation $location, string $closedOn, array $loads): PithStockDepletion
{
    return PithStockDepletion::create([
        'pith_location_id' => $location->id,
        'depletion_date' => $closedOn,
        'first_pith_inventory_id' => collect($loads)->min('id'),
        'last_pith_inventory_id' => collect($loads)->max('id'),
        'total_cft_offloaded' => collect($loads)->sum('cft_offloaded'),
        'total_cft_taken_calculated' => collect($loads)->sum('taken_cft_calculated'),
    ]);
}

function panel(string $role)
{
    test()->actingAs(test()->users[$role]);

    return Livewire::test(PithLocationLoads::class, ['locationId' => test()->location->id, 'teamId' => test()->teamId]);
}

// ── Batches and entries ──────────────────────────────────────────────────────

it('numbers batches and dates the open one from its first entry', function () {
    panelClosedBatch($this->location, '2026-03-02', [panelLoad($this->location, '2026-01-21', 100, 90, archived: true)]);
    panelClosedBatch($this->location, '2026-06-02', [panelLoad($this->location, '2026-03-04', 200, 150, archived: true)]);
    panelLoad($this->location, '2026-06-05', 300);
    panelWatering($this->location, '2026-06-04', 500);

    $batch = app(PithLocationLedgerService::class)->currentBatch($this->location);

    expect($batch['number'])->toBe(3)
        ->and($batch['start_date'])->toBe('2026-06-04')
        ->and($batch['stock'])->toBe(300.0)
        ->and($batch['water_litres'])->toBe(500)
        ->and($batch['water_cycles'])->toBe(1);

    panel('reader')->assertSee('Batch 3 · since 04 Jun 2026')->assertSee('Past Batches');
});

it('lists stock and watering entries newest first, with production loads read-only', function () {
    $manual = panelLoad($this->location, '2026-09-10', inward: 400);
    $fromProduction = panelLoad($this->location, '2026-09-12', inward: 250, productionId: 999999);
    $out = panelLoad($this->location, '2026-09-15', outward: 100);
    $watering = panelWatering($this->location, '2026-09-18', 2200);

    $entries = collect(app(PithLocationLedgerService::class)->currentBatch($this->location)['entries']);

    expect($entries->pluck('key')->all())->toBe(["water-{$watering->id}", "out-{$out->id}", "in-{$fromProduction->id}", "in-{$manual->id}"])
        ->and($entries->firstWhere('id', $fromProduction->id)['source'])->toBe('production')
        ->and($entries->firstWhere('id', $fromProduction->id)['editable'])->toBeFalse()
        ->and($entries->firstWhere('id', $manual->id)['editable'])->toBeTrue();

    panel('reader')->assertSee('Production')->assertSee('2,200 L')->assertSee('4 entries');
});

it('filters entries to stock or watering', function () {
    panelLoad($this->location, '2026-09-10', inward: 400);
    panelWatering($this->location, '2026-09-18', 2200);

    // The rows' wire:keys, since the Watered total above the list shows either way.
    panel('reader')
        ->call('filterEntries', 'water')
        ->assertSee('1 entry')
        ->assertSeeHtml('wire:key="entry-water-')
        ->assertDontSeeHtml('wire:key="entry-in-')
        ->call('filterEntries', 'stock')
        ->assertSee('1 entry')
        ->assertSeeHtml('wire:key="entry-in-')
        ->assertDontSeeHtml('wire:key="entry-water-');
});

// ── Entry form ───────────────────────────────────────────────────────────────

it('saves a Stock In, a Stock Out and a Watering from the form', function () {
    $component = panel('staff');

    foreach (['in' => '500', 'out' => '120', 'water' => '1800'] as $type => $qty) {
        $component->call('openForm', $type)
            ->set('formQty', $qty)
            ->set('formDate', '2026-09-22')
            ->call('saveForm')
            ->assertHasNoErrors()
            ->assertSet('formType', null)
            ->assertDispatched('pith-inventory-refresh');
    }

    $component->assertDispatched('pi-toast', message: 'Watering saved');

    expect(PithInventory::where('pith_location_id', $this->location->id)->sum('cft_offloaded'))->toEqual(500)
        ->and(PithInventory::where('pith_location_id', $this->location->id)->sum('taken_cft_calculated'))->toEqual(120)
        ->and(PithWatering::where('pith_location_id', $this->location->id)->currentBatch()->sum('litres'))->toEqual(1800);
});

it('still saves a Stock Out larger than the stock in hand', function () {
    panelLoad($this->location, '2026-09-10', inward: 100);

    panel('staff')
        ->call('openForm', 'out')
        ->set('formQty', '250')
        ->set('formDate', '2026-09-22')
        ->call('saveForm')
        ->assertHasNoErrors();

    expect(app(PithLocationLedgerService::class)->currentBatch($this->location)['stock'])->toBe(-150.0);
});

it('rejects a missing, zero or oversized quantity', function (string $qty) {
    panel('staff')
        ->call('openForm', 'water')
        ->set('formQty', $qty)
        ->set('formDate', '2026-09-22')
        ->call('saveForm')
        ->assertHasErrors('formQty')
        ->assertSet('formType', 'water');

    expect(PithWatering::where('pith_location_id', $this->location->id)->count())->toBe(0);
})->with(['', '0', '1000000']);

it('edits and deletes an entry of the open batch', function () {
    $load = panelLoad($this->location, '2026-09-10', inward: 400);
    $watering = panelWatering($this->location, '2026-09-18', 2200);

    panel('supervisor')
        ->call('editEntry', 'in', $load->id)
        ->assertSet('formType', 'in')
        ->assertSet('formQty', '400')
        ->set('formQty', '450')
        ->call('saveForm')
        ->assertDispatched('pi-toast', message: 'Entry updated');

    expect((float) $load->refresh()->cft_offloaded)->toBe(450.0);

    panel('admin')
        ->call('confirmDeleteEntry', 'water', $watering->id)
        ->assertSet('deletingEntry.kind', 'water')
        ->assertSee('Delete this watering entry?')
        ->call('deleteConfirmedEntry')
        ->assertDispatched('pi-toast', message: 'Watering entry deleted');

    expect(PithWatering::find($watering->id))->toBeNull();
});

it('refuses to edit or delete a production load', function () {
    $fromProduction = panelLoad($this->location, '2026-09-12', inward: 250, productionId: 999999);

    panel('admin')
        ->call('editEntry', 'in', $fromProduction->id)
        ->assertSet('formType', null)
        ->call('confirmDeleteEntry', 'in', $fromProduction->id)
        ->assertSet('deletingEntry', null)
        // A forged form submit is refused on the server too.
        ->set('formType', 'in')
        ->set('formEntryId', $fromProduction->id)
        ->set('formQty', '1')
        ->set('formDate', '2026-09-22')
        ->call('saveForm')
        ->assertDispatched('pi-toast', message: 'That entry can no longer be changed.', error: true);

    expect((float) $fromProduction->refresh()->cft_offloaded)->toBe(250.0);
});

it('never touches another location\'s entry', function () {
    $other = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Other Yard', 'is_active' => 1]);
    $theirs = panelLoad($other, '2026-09-12', inward: 250);

    panel('admin')
        ->call('editEntry', 'in', $theirs->id)
        ->assertSet('formType', null)
        ->set('formType', 'in')
        ->set('formEntryId', $theirs->id)
        ->set('formQty', '1')
        ->set('formDate', '2026-09-22')
        ->call('saveForm');

    expect((float) $theirs->refresh()->cft_offloaded)->toBe(250.0);
});

// ── Permissions ──────────────────────────────────────────────────────────────

it('shows each role only the buttons it can use', function () {
    panelLoad($this->location, '2026-09-10', inward: 400);

    // The add buttons by their action, since "Stock In" and "Watering" also appear as plain text.
    panel('reader')
        ->assertDontSeeHtml("openForm('in')")
        ->assertDontSeeHtml("openForm('out')")
        ->assertDontSeeHtml("openForm('water')")
        ->assertDontSeeHtml('editEntry(')
        ->assertDontSee('Close Batch');

    panel('staff')
        ->assertSeeHtml("openForm('in')")
        ->assertSeeHtml("openForm('out')")
        ->assertSeeHtml("openForm('water')")
        ->assertDontSeeHtml('editEntry(')
        ->assertDontSeeHtml('confirmDeleteEntry(')
        ->assertDontSee('Close Batch');

    panel('supervisor')
        ->assertSeeHtml('editEntry(')
        ->assertDontSeeHtml('confirmDeleteEntry(')
        ->assertSee('Close Batch');

    panel('admin')->assertSeeHtml('confirmDeleteEntry(');
});

it('keeps writes a role lacks from reaching the database', function () {
    $load = panelLoad($this->location, '2026-09-10', inward: 400);

    panel('reader')->call('openForm', 'in')->assertSet('formType', null);
    panel('staff')->call('confirmDeleteEntry', 'in', $load->id)->assertSet('deletingEntry', null);
    panel('staff')->call('confirmCloseLocation')->assertSet('showCloseDialog', false);

    expect(PithInventory::find($load->id))->not->toBeNull();
});

// ── Closed batches ───────────────────────────────────────────────────────────

it('shows a closed batch read-only with its own entries', function () {
    $old = panelLoad($this->location, '2026-05-01', 800, 700, archived: true);
    $closed = panelClosedBatch($this->location, '2026-06-02', [$old]);
    panelWatering($this->location, '2026-05-20', 2400, $closed->id);
    panelLoad($this->location, '2026-09-10', inward: 400);

    panel('admin')
        ->call('viewBatch', $closed->id)
        ->assertSet('viewDepletionId', $closed->id)
        ->assertSee('VIEWING CLOSED BATCH · READ-ONLY')
        ->assertSee('Batch 1 · 01 May – 02 Jun 2026')
        ->assertSee('2,400 L')
        ->assertDontSeeHtml("openForm('in')")
        ->assertDontSeeHtml('editEntry(')
        ->call('openForm', 'in')
        ->assertSet('formType', null)
        ->call('backToCurrent')
        ->assertSet('viewDepletionId', null)
        ->assertSeeHtml("openForm('in')");
});

it('lists past batches with what was not taken out and the water used', function () {
    $closed = panelClosedBatch($this->location, '2026-06-02', [panelLoad($this->location, '2026-05-01', 800, 700, archived: true)]);
    panelWatering($this->location, '2026-05-20', 2400, $closed->id);
    panelWatering($this->location, '2026-05-25', 600, $closed->id);

    $past = app(PithLocationLedgerService::class)->pastBatches($this->location);

    expect($past)->toHaveCount(1)
        ->and($past[0]['number'])->toBe(1)
        ->and($past[0]['stock'])->toBe(100.0)
        ->and($past[0]['water_litres'])->toBe(3000)
        ->and($past[0]['water_cycles'])->toBe(2);

    panel('reader')->call('openPastBatches')->assertSee('1 closed batch')->assertSee('3,000 L')->assertSee('2 cycles');
});

it('ignores a closed batch that belongs to another location', function () {
    $other = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Other Yard', 'is_active' => 1]);
    $theirs = panelClosedBatch($other, '2026-06-02', [panelLoad($other, '2026-05-01', 800, 700, archived: true)]);

    panel('admin')->call('viewBatch', $theirs->id)->assertSet('viewDepletionId', null);
});

it('does not offer to close a batch holding only watering', function () {
    panelWatering($this->location, '2026-09-18', 2200);

    panel('supervisor')
        ->assertDontSee('Close Batch')
        ->call('confirmCloseLocation')
        ->assertSet('showCloseDialog', false);
});

it('shows the stock not taken out before closing', function () {
    panelLoad($this->location, '2026-09-10', inward: 400, outward: 0);
    panelLoad($this->location, '2026-09-12', outward: 150);

    panel('supervisor')
        ->call('confirmCloseLocation')
        ->assertSee('Close Batch 1?')
        ->assertSee('Stock not taken out')
        ->assertSee('250 cft')
        ->assertSee('starts empty as Batch 2');
});

// ── Old route ────────────────────────────────────────────────────────────────

it('redirects the old location page into the inventory panel', function () {
    $this->actingAs($this->users['reader'])
        ->get("/{$this->teamId}/pith/taken/{$this->location->id}")
        ->assertRedirect(route('pith.inventory', ['team' => $this->teamId, 'location' => $this->location->id]));
});

it('rejects a panel for another team\'s location', function () {
    $foreignTeam = RbacFixtures::team('Pith Panel Foreign');
    $foreign = PithLocation::create(['team_id' => $foreignTeam, 'name' => 'Foreign Yard', 'is_active' => 1]);

    $this->actingAs($this->users['admin']);

    Livewire::test(PithLocationLoads::class, ['locationId' => $foreign->id, 'teamId' => $this->teamId])
        ->assertNotFound();
});

it('leaves out the cycle count when nothing has been watered', function () {
    $closed = panelClosedBatch($this->location, '2026-06-02', [panelLoad($this->location, '2026-05-01', 800, 700, archived: true)]);
    panelLoad($this->location, '2026-09-10', inward: 400);

    panel('reader')
        ->assertDontSee('0 cycles')
        ->call('openPastBatches')
        ->assertDontSee('0 cycles');

    panelWatering($this->location, '2026-09-18', 2200);

    panel('reader')->assertSee('1 cycle');

    $this->actingAs($this->users['reader']);
    Livewire::test(App\Livewire\Pulse\Pith\PithInventory::class)->assertDontSee('0 cycles');
});
