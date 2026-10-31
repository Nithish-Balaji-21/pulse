<?php

use App\Livewire\Pulse\Pith\PithLocationLoads;
use App\Livewire\Pulse\Pith\PithStockTransfer;
use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithLocationGroup;
use App\Models\Pulse\PithTransfer;
use App\Services\NotificationSenderService;
use App\Services\Pulse\PithLocationLedgerService;
use App\Services\Pulse\PithStockTransferService;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Pulse\Support\RbacFixtures;

/**
 * Moving pith between locations: the /pith/transfer API used by the mobile app, the service
 * rules behind it (same location, team scope, inactive destination, closed batch), how the two
 * ledger rows read back, and the web popup and location panel. Like Stock Out, a transfer is not
 * capped at the source's stock in hand — it can leave a location negative.
 */
beforeEach(function () {
    $this->travelTo('2026-09-23 10:00:00');
    $this->teamId = RbacFixtures::team('Pith Transfer');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);

    $this->bunker = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Alam Bunker', 'is_active' => 1]);
    $this->east = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Alam East', 'is_active' => 1]);

    $this->otherTeamId = RbacFixtures::team('Pith Transfer Other');
    $this->foreign = PithLocation::create(['team_id' => $this->otherTeamId, 'name' => 'Yard Z', 'is_active' => 1]);

    transferStockIn($this->bunker, 1000);

    $this->mock(NotificationSenderService::class, fn ($mock) => $mock->shouldReceive('notifyTeamUsers'));
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

function transferStockIn(PithLocation $location, float $cft): PithInventory
{
    return PithInventory::create([
        'pith_location_id' => $location->id,
        'date' => '2026-09-20',
        'cft_offloaded' => $cft,
        'is_archived' => 0,
    ]);
}

function transferStockAt(PithLocation $location): float
{
    return app(PithStockTransferService::class)->availableStock($location);
}

/**
 * Transfers out of this test's team only; the dev database may hold real ones.
 */
function teamTransfers()
{
    return PithTransfer::whereHas('fromLocation', fn ($query) => $query->where('team_id', test()->teamId));
}

function transferPost(array $override = [])
{
    return test()->postJson('/api/pith/transfer/store', array_merge([
        'from_location_id' => test()->bunker->id,
        'to_location_id' => test()->east->id,
        'cft' => 400,
        'note' => 'To drying yard',
    ], $override));
}

// ── API: store ───────────────────────────────────────────────────────────────

it('moves stock out of one location and into another', function () {
    Sanctum::actingAs($this->users['staff']);

    $response = transferPost()->assertCreated()
        ->assertJsonPath('transfer.cft', 400)
        ->assertJsonPath('transfer.note', 'To drying yard')
        ->assertJsonPath('transfer.from_location_name', 'Alam Bunker')
        ->assertJsonPath('transfer.to_location_name', 'Alam East')
        ->assertJsonPath('transfer.date', '2026-09-23');

    expect(transferStockAt($this->bunker))->toBe(600.0)
        ->and(transferStockAt($this->east))->toBe(400.0);

    $rows = PithInventory::where('pith_transfer_id', $response->json('transfer.id'))->get();

    expect($rows)->toHaveCount(2)
        ->and((float) $rows->firstWhere('pith_location_id', $this->bunker->id)->taken_cft_calculated)->toBe(400.0)
        ->and((float) $rows->firstWhere('pith_location_id', $this->east->id)->cft_offloaded)->toBe(400.0);
});

it('allows transferring exactly the stock in hand, with decimals', function () {
    Sanctum::actingAs($this->users['staff']);
    transferStockIn($this->bunker, 0.5);

    transferPost(['cft' => 1000.5])->assertCreated();

    expect(transferStockAt($this->bunker))->toBe(0.0)
        ->and(transferStockAt($this->east))->toBe(1000.5);
});

it('allows transferring more than the stock in hand', function () {
    Sanctum::actingAs($this->users['staff']);

    $response = transferPost(['cft' => 1000.01])->assertCreated();

    expect(teamTransfers()->count())->toBe(1)
        ->and(transferStockAt($this->bunker))->toBe(-0.01)
        ->and(transferStockAt($this->east))->toBe(1000.01)
        ->and(PithInventory::where('pith_transfer_id', $response->json('transfer.id'))->count())->toBe(2);
});

it('counts only the open batch as stock in hand', function () {
    PithInventory::create(['pith_location_id' => $this->bunker->id, 'date' => '2026-01-01', 'cft_offloaded' => 5000, 'is_archived' => 1]);

    // The archived 5,000 cft batch is not counted; only the open batch's 1,000 cft is.
    expect(transferStockAt($this->bunker))->toBe(1000.0);
});

it('reports negative available stock once a location has been overdrawn', function () {
    // A Stock Out larger than stock in hand, as PithLocationLoads already allows.
    PithInventory::create(['pith_location_id' => $this->bunker->id, 'date' => '2026-09-23', 'taken_cft_calculated' => 1500]);

    expect(transferStockAt($this->bunker))->toBe(-500.0);
});

it('rejects invalid transfer input', function (array $override, string $errorKey) {
    Sanctum::actingAs($this->users['staff']);

    transferPost($override)->assertUnprocessable()->assertJsonValidationErrors([$errorKey]);

    expect(teamTransfers()->count())->toBe(0);
})->with([
    'zero quantity' => [['cft' => 0], 'cft'],
    'negative quantity' => [['cft' => -5], 'cft'],
    'text quantity' => [['cft' => 'ten'], 'cft'],
    'missing quantity' => [['cft' => null], 'cft'],
    'too large' => [['cft' => 1000000], 'cft'],
    'future date' => [['date' => '2026-09-24'], 'date'],
    'long comment' => [['note' => str_repeat('x', 256)], 'note'],
    'missing destination' => [['to_location_id' => null], 'to_location_id'],
]);

it('refuses a transfer to the same location', function () {
    Sanctum::actingAs($this->users['staff']);

    transferPost(['to_location_id' => $this->bunker->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.to_location_id.0', 'Source and destination locations cannot be the same.');
});

it('refuses an inactive destination', function () {
    Sanctum::actingAs($this->users['staff']);
    $this->east->update(['is_active' => 0]);

    transferPost()->assertUnprocessable()->assertJsonValidationErrors(['to_location_id']);
});

it('keeps both locations inside the team', function (string $side) {
    Sanctum::actingAs($this->users['staff']);

    transferPost([$side => $this->foreign->id])->assertUnprocessable()->assertJsonValidationErrors([$side]);

    expect(teamTransfers()->count())->toBe(0);
})->with(['from_location_id', 'to_location_id']);

it('needs both the inward and outward create capabilities', function (string $revoked) {
    RbacFixtures::revoke($this->teamId, 'staff', [$revoked]);
    Sanctum::actingAs($this->users['staff']);

    transferPost()->assertForbidden();
})->with(['pith_inventory_inward:create', 'pith_inventory_outward:create']);

it('is not open to readers', function () {
    Sanctum::actingAs($this->users['reader']);

    transferPost()->assertForbidden();
});

// ── API: reading and deleting ────────────────────────────────────────────────

it('labels transfer rows on the location read', function () {
    Sanctum::actingAs($this->users['staff']);
    transferPost()->assertCreated();

    $out = collect($this->getJson("/api/pith/location/{$this->bunker->id}")->assertOk()->json('pithLoads'))
        ->firstWhere('pith_transfer_id', '!=', null);

    expect($out['transfer'])->toMatchArray([
        'direction' => 'out',
        'to_location_name' => 'Alam East',
        'note' => 'To drying yard',
    ]);

    $in = collect($this->getJson("/api/pith/location/{$this->east->id}")->json('pithLoads'))->first();

    expect($in['transfer']['direction'])->toBe('in')
        ->and($in['transfer']['from_location_name'])->toBe('Alam Bunker');
});

it('reverses both sides when a transfer is deleted', function () {
    Sanctum::actingAs($this->users['admin']);
    $id = transferPost()->json('transfer.id');

    $this->deleteJson("/api/pith/transfer/delete/{$id}")->assertOk();

    expect(teamTransfers()->count())->toBe(0)
        ->and(PithInventory::where('pith_transfer_id', $id)->count())->toBe(0)
        ->and(transferStockAt($this->bunker))->toBe(1000.0)
        ->and(transferStockAt($this->east))->toBe(0.0);
});

it('keeps a transfer once either batch is closed', function () {
    Sanctum::actingAs($this->users['admin']);
    $id = transferPost()->json('transfer.id');
    PithInventory::where('pith_transfer_id', $id)->where('pith_location_id', $this->east->id)->update(['is_archived' => 1]);

    $this->deleteJson("/api/pith/transfer/delete/{$id}")->assertUnprocessable();

    expect(PithTransfer::whereKey($id)->exists())->toBeTrue();
});

it('needs both delete capabilities to delete a transfer', function () {
    Sanctum::actingAs($this->users['staff']);
    $id = transferPost()->json('transfer.id');

    Sanctum::actingAs($this->users['supervisor']);
    $this->deleteJson("/api/pith/transfer/delete/{$id}")->assertForbidden();
});

it('does not reveal another team\'s transfer', function () {
    $other = PithLocation::create(['team_id' => $this->otherTeamId, 'name' => 'Yard Y', 'is_active' => 1]);
    $transfer = PithTransfer::create(['from_pith_location_id' => $this->foreign->id, 'to_pith_location_id' => $other->id, 'date' => '2026-09-20', 'cft' => 10]);
    Sanctum::actingAs($this->users['admin']);

    $this->deleteJson("/api/pith/transfer/delete/{$transfer->id}")->assertNotFound();
});

it('refuses to edit or delete a transfer row as a plain stock entry', function () {
    Sanctum::actingAs($this->users['admin']);
    $id = transferPost()->json('transfer.id');
    $out = PithInventory::where('pith_transfer_id', $id)->where('pith_location_id', $this->bunker->id)->first();
    $in = PithInventory::where('pith_transfer_id', $id)->where('pith_location_id', $this->east->id)->first();

    $this->putJson("/api/pith/taken/update/{$out->id}", ['date' => '2026-09-23', 'takenCft' => 1])->assertUnprocessable();
    $this->deleteJson("/api/pith/taken/delete/{$out->id}")->assertUnprocessable();
    $this->putJson("/api/pith/surplus/update/{$in->id}", ['date' => '2026-09-23', 'loads_offloaded' => 1])->assertUnprocessable();
    $this->deleteJson("/api/pith/surplus/delete/{$in->id}")->assertUnprocessable();

    expect(transferStockAt($this->bunker))->toBe(600.0)
        ->and(transferStockAt($this->east))->toBe(400.0);
});

// ── Ledger and web ───────────────────────────────────────────────────────────

it('shows transfers in the ledger as non-editable transfer entries', function () {
    $transfer = app(PithStockTransferService::class)->transfer($this->teamId, $this->bunker->id, $this->east->id, 250, '2026-09-23', 'Shed repair');

    $entry = collect(app(PithLocationLedgerService::class)->currentBatch($this->bunker)['entries'])
        ->firstWhere('source', 'transfer');

    expect($entry)->toMatchArray(['kind' => 'out', 'qty' => 250.0, 'editable' => false])
        ->and($entry['transfer'])->toMatchArray([
            'id' => $transfer->id,
            'direction' => 'out',
            'peer_location_name' => 'Alam East',
            'note' => 'Shed repair',
            'changeable' => true,
        ]);
});

it('transfers from the web popup opened on a location', function () {
    $this->actingAs($this->users['staff']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('openTransfer', $this->bunker->id)
        ->assertSet('fromLocked', true)
        ->assertSet('fromLocationId', $this->bunker->id)
        ->assertSet('toLocationId', $this->east->id)
        ->assertSet('date', now()->toDateString())
        ->set('qty', '300')
        ->set('date', '2026-09-20')
        ->set('note', 'Before pressing')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertDispatched('pith-inventory-refresh');

    expect(transferStockAt($this->bunker))->toBe(700.0)
        ->and(teamTransfers()->first()->note)->toBe('Before pressing')
        ->and(teamTransfers()->first()->date->format('Y-m-d'))->toBe('2026-09-20');
});

it('takes a whole-number quantity and a past date in the web popup', function (string $field, string $value) {
    $this->actingAs($this->users['staff']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('openTransfer', $this->bunker->id)
        ->set('qty', '300')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field]);

    expect(teamTransfers()->count())->toBe(0);
})->with([
    'no quantity' => ['qty', ''],
    'zero quantity' => ['qty', '0'],
    'future date' => ['date', now()->addDay()->toDateString()],
    'bad date' => ['date', '20-09-2026'],
]);

it('allows transferring more than the stock in hand from the web popup', function () {
    $this->actingAs($this->users['staff']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('openTransfer')
        ->set('toLocationId', (string) $this->east->id)
        ->set('qty', '5000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('open', false);

    expect(teamTransfers()->count())->toBe(1)
        ->and(transferStockAt($this->bunker))->toBe(-4000.0)
        ->and(transferStockAt($this->east))->toBe(5000.0);
});

it('transfers out of a location with no stock', function () {
    $empty = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Alam West', 'is_active' => 1]);
    $this->actingAs($this->users['staff']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('openTransfer', $empty->id)
        ->assertSet('fromLocked', true)
        ->assertSet('fromLocationId', $empty->id)
        ->set('toLocationId', (string) $this->east->id)
        ->set('qty', '50')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('open', false);

    expect(transferStockAt($empty))->toBe(-50.0)
        ->and(transferStockAt($this->east))->toBe(50.0);
});

it('shows the Transfer button on a location with no stock', function () {
    $empty = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Alam West', 'is_active' => 1]);
    $this->actingAs($this->users['staff']);

    Livewire::test(PithLocationLoads::class, ['locationId' => $empty->id, 'teamId' => $this->teamId])
        ->assertSee('⇄ Transfer');
});

it('toasts instead of opening when the team has no locations', function () {
    $emptyTeamId = RbacFixtures::team('Pith Transfer Empty');
    RbacFixtures::bindTeam($emptyTeamId);
    $users = RbacFixtures::usersForAllRoles($emptyTeamId);
    $this->actingAs($users['staff']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $emptyTeamId])
        ->call('openTransfer')
        ->assertSet('open', false)
        ->assertDispatched('pi-toast');
});

it('swaps the two locations from the header popup', function () {
    $this->actingAs($this->users['staff']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('openTransfer')
        ->call('swap')
        ->assertSet('fromLocationId', $this->east->id)
        ->assertSet('toLocationId', $this->bunker->id);
});

it('deletes a transfer from the location panel', function () {
    app(PithStockTransferService::class)->transfer($this->teamId, $this->bunker->id, $this->east->id, 250, '2026-09-23');
    $this->actingAs($this->users['admin']);
    $transferId = teamTransfers()->value('id');

    Livewire::test(PithLocationLoads::class, ['locationId' => $this->bunker->id, 'teamId' => $this->teamId])
        ->assertSee('Transfer Out → Alam East')
        ->call('confirmDeleteTransfer', $transferId)
        ->assertSet('deletingTransfer.id', $transferId)
        ->call('deleteConfirmedTransfer')
        ->assertDispatched('pith-inventory-refresh');

    expect(teamTransfers()->count())->toBe(0)
        ->and(transferStockAt($this->bunker))->toBe(1000.0);
});

// ── Edit ─────────────────────────────────────────────────────────────────────

function transferPut(int $id, array $override = [])
{
    return test()->putJson("/api/pith/transfer/update/{$id}", array_merge([
        'to_location_id' => test()->east->id,
        'cft' => 400,
        'note' => 'To drying yard',
    ], $override));
}

it('edits a transfer\'s quantity and comment on both sides', function () {
    Sanctum::actingAs($this->users['supervisor']);
    $id = transferPost()->json('transfer.id');

    transferPut($id, ['cft' => 650.5, 'note' => 'More than planned'])
        ->assertOk()
        ->assertJsonPath('transfer.cft', 650.5)
        ->assertJsonPath('transfer.note', 'More than planned');

    expect(transferStockAt($this->bunker))->toBe(349.5)
        ->and(transferStockAt($this->east))->toBe(650.5)
        ->and(PithInventory::where('pith_transfer_id', $id)->count())->toBe(2);
});

it('re-edits a transfer back to its own full quantity', function () {
    Sanctum::actingAs($this->users['supervisor']);
    $id = transferPost(['cft' => 1000])->json('transfer.id');

    // The bunker now holds nothing, but the 1,000 cft being edited can go back out.
    transferPut($id, ['cft' => 1000])->assertOk();

    expect(transferStockAt($this->bunker))->toBe(0.0)
        ->and(transferStockAt($this->east))->toBe(1000.0);
});

it('allows editing a transfer to more than the stock in hand', function () {
    Sanctum::actingAs($this->users['supervisor']);
    $id = transferPost(['cft' => 1000])->json('transfer.id');

    transferPut($id, ['cft' => 1500])->assertOk();

    expect(transferStockAt($this->bunker))->toBe(-500.0)
        ->and(transferStockAt($this->east))->toBe(1500.0);
});

it('moves a transfer to a new destination with a fresh inward row', function () {
    Sanctum::actingAs($this->users['supervisor']);
    $north = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Alam North', 'is_active' => 1]);
    $id = transferPost()->json('transfer.id');
    $oldIn = PithInventory::where('pith_transfer_id', $id)->where('pith_location_id', $this->east->id)->value('id');

    transferPut($id, ['to_location_id' => $north->id])->assertOk()->assertJsonPath('transfer.to_location_name', 'Alam North');

    $newIn = PithInventory::where('pith_transfer_id', $id)->where('pith_location_id', $north->id)->first();

    expect(transferStockAt($this->east))->toBe(0.0)
        ->and(transferStockAt($north))->toBe(400.0)
        ->and(transferStockAt($this->bunker))->toBe(600.0)
        ->and(PithInventory::find($oldIn))->toBeNull()
        ->and($newIn->id)->toBeGreaterThan($oldIn);
});

it('refuses an edit to the source itself or an inactive new destination', function () {
    Sanctum::actingAs($this->users['supervisor']);
    $shed = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Old Shed', 'is_active' => 0]);
    $id = transferPost()->json('transfer.id');

    transferPut($id, ['to_location_id' => $this->bunker->id])->assertUnprocessable()->assertJsonValidationErrors(['to_location_id']);
    transferPut($id, ['to_location_id' => $shed->id])->assertUnprocessable()->assertJsonValidationErrors(['to_location_id']);
    transferPut($id, ['to_location_id' => $this->foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['to_location_id']);
});

it('refuses to edit once either batch is closed', function () {
    Sanctum::actingAs($this->users['supervisor']);
    $id = transferPost()->json('transfer.id');
    PithInventory::where('pith_transfer_id', $id)->where('pith_location_id', $this->bunker->id)->update(['is_archived' => 1]);

    transferPut($id, ['cft' => 100])->assertUnprocessable();

    expect(PithTransfer::find($id)->cft)->toBe(400.0);
});

it('needs both the inward and outward edit capabilities', function (string $revoked) {
    Sanctum::actingAs($this->users['staff']);
    $id = transferPost()->json('transfer.id');
    RbacFixtures::revoke($this->teamId, 'supervisor', [$revoked]);
    Sanctum::actingAs($this->users['supervisor']);

    transferPut($id, ['cft' => 100])->assertForbidden();
})->with(['pith_inventory_inward:edit', 'pith_inventory_outward:edit']);

it('does not let staff, who can only create, edit a transfer', function () {
    Sanctum::actingAs($this->users['staff']);
    $id = transferPost()->json('transfer.id');

    transferPut($id, ['cft' => 100])->assertForbidden();
});

it('edits a transfer from the web popup', function () {
    $transfer = app(PithStockTransferService::class)->transfer($this->teamId, $this->bunker->id, $this->east->id, 250.5, '2026-09-22', 'First pass');
    $this->actingAs($this->users['supervisor']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('editTransfer', $transfer->id)
        ->assertSet('open', true)
        ->assertSet('fromLocked', true)
        ->assertSet('qty', '251')
        ->assertSet('date', '2026-09-22')
        ->assertSet('note', 'First pass')
        ->assertSee('Edit Transfer')
        ->set('qty', '300')
        ->set('date', '2026-09-21')
        ->set('note', 'Second pass')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('open', false)
        ->assertSet('editingTransferId', null)
        ->assertDispatched('pith-inventory-refresh');

    $transfer->refresh();

    expect($transfer->cft)->toBe(300.0)
        ->and($transfer->note)->toBe('Second pass')
        ->and($transfer->date->format('Y-m-d'))->toBe('2026-09-21')
        ->and(transferStockAt($this->bunker))->toBe(700.0);
});

it('shows the edit and delete actions on a transfer row to those allowed', function () {
    app(PithStockTransferService::class)->transfer($this->teamId, $this->bunker->id, $this->east->id, 250, '2026-09-23');

    $this->actingAs($this->users['admin']);
    Livewire::test(PithLocationLoads::class, ['locationId' => $this->bunker->id, 'teamId' => $this->teamId])
        ->assertSee('Edit transfer')
        ->assertSee('Delete transfer');

    $this->actingAs($this->users['staff']);
    Livewire::test(PithLocationLoads::class, ['locationId' => $this->bunker->id, 'teamId' => $this->teamId])
        ->assertDontSee('Edit transfer')
        ->assertDontSee('Delete transfer');
});

// ── Source location only ─────────────────────────────────────────────────────

it('marks only the source row of a transfer as changeable', function () {
    app(PithStockTransferService::class)->transfer($this->teamId, $this->bunker->id, $this->east->id, 250, '2026-09-23');
    $ledger = app(PithLocationLedgerService::class);

    $out = collect($ledger->currentBatch($this->bunker)['entries'])->firstWhere('source', 'transfer');
    $in = collect($ledger->currentBatch($this->east)['entries'])->firstWhere('source', 'transfer');

    expect($out['transfer']['changeable'])->toBeTrue()
        ->and($in['transfer']['changeable'])->toBeFalse();
});

it('keeps the destination\'s transfer row read-only, even for an admin', function () {
    app(PithStockTransferService::class)->transfer($this->teamId, $this->bunker->id, $this->east->id, 250, '2026-09-23');
    $transferId = teamTransfers()->value('id');
    $this->actingAs($this->users['admin']);

    Livewire::test(PithLocationLoads::class, ['locationId' => $this->east->id, 'teamId' => $this->teamId])
        ->assertSee('Transfer In ← Alam Bunker')
        ->assertDontSee('Edit transfer')
        ->assertDontSee('Delete transfer')
        ->call('confirmDeleteTransfer', $transferId)
        ->assertSet('deletingTransfer', null)
        // Even a forged confirmation from the destination panel is refused.
        ->set('deletingTransfer', ['id' => $transferId, 'qty' => 250, 'date' => '2026-09-23', 'direction' => 'in', 'peer' => 'Alam Bunker'])
        ->call('deleteConfirmedTransfer');

    expect(teamTransfers()->count())->toBe(1)
        ->and(transferStockAt($this->east))->toBe(250.0);
});

it('refuses to open the edit popup from the destination', function () {
    $transfer = app(PithStockTransferService::class)->transfer($this->teamId, $this->bunker->id, $this->east->id, 250, '2026-09-23');
    $this->actingAs($this->users['admin']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('editTransfer', $transfer->id, locationId: $this->east->id)
        ->assertSet('open', false)
        ->call('editTransfer', $transfer->id, locationId: $this->bunker->id)
        ->assertSet('open', true)
        ->assertSet('editingTransferId', $transfer->id);
});

// ── Grouped dropdowns ────────────────────────────────────────────────────────

it('lists a flat list with no optgroups when the team has no location groups', function () {
    $this->actingAs($this->users['staff']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('openTransfer')
        ->assertDontSeeHtml('<optgroup');
});

it('groups the From and To options by location group', function () {
    $north = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'North Yard', 'display_order' => 1, 'is_active' => 1]);
    $south = PithLocationGroup::create(['team_id' => $this->teamId, 'name' => 'South Yard', 'display_order' => 2, 'is_active' => 1]);
    $this->bunker->update(['group_id' => $north->id]);
    $this->east->update(['group_id' => $south->id]);
    $this->actingAs($this->users['staff']);

    Livewire::test(PithStockTransfer::class, ['teamId' => $this->teamId])
        ->call('openTransfer')
        ->assertSeeHtml('<optgroup label="North Yard"')
        ->assertSeeHtml('<optgroup label="South Yard"');
});

it('gives each role the same transfer actions as its inward and outward rights', function (string $role, bool $create, bool $edit, bool $delete) {
    Sanctum::actingAs($this->users['owner']);
    $id = transferPost()->json('transfer.id');
    Sanctum::actingAs($this->users[$role]);

    $expect = fn ($response, bool $allowed) => $allowed ? $response->assertSuccessful() : $response->assertForbidden();

    $expect(transferPost(['cft' => 10]), $create);
    $expect(transferPut($id, ['cft' => 300]), $edit);
    $expect($this->deleteJson("/api/pith/transfer/delete/{$id}"), $delete);
})->with([
    'reader' => ['reader', false, false, false],
    'staff' => ['staff', true, false, false],
    'supervisor' => ['supervisor', true, true, false],
    'admin' => ['admin', true, true, true],
    'owner' => ['owner', true, true, true],
]);
