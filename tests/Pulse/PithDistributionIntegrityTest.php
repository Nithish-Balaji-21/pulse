<?php

use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithProduction;
use App\Traits\ManagePithInventory;
use Tests\Pulse\Support\TeamFactory;

/**
 * A production's offloads must never exceed what that shift produced — the invariant
 * system:check_errors reconciles every night.
 *
 * Both save paths used to write cft_produced first and validate the distribution after,
 * so lowering produced below the offloaded total showed the operator a rejection while
 * leaving the lowered figure committed against loads that were never adjusted. The
 * guard announced the mismatch and created it in the same breath.
 */
class DistributionHost
{
    use ManagePithInventory;
}

beforeEach(function () {
    $this->teamId = TeamFactory::create('Distribution Factory '.uniqid());
    $this->host = new DistributionHost;

    $this->production = PithProduction::create([
        'team_id' => $this->teamId,
        'shift_id' => 1,
        'shift_name' => 'Shift 1',
        'shift_date' => '2026-10-01',
        'start_time' => '2026-10-01 08:00:00',
        'end_time' => '2026-10-01 19:59:59',
        'cft_produced' => 1900,
    ]);

    $this->north = PithLocation::create(['team_id' => $this->teamId, 'name' => 'north '.uniqid(), 'is_active' => true]);
    $this->south = PithLocation::create(['team_id' => $this->teamId, 'name' => 'south '.uniqid(), 'is_active' => true]);

    foreach ([[$this->north, 900], [$this->south, 1000]] as [$location, $cft]) {
        PithInventory::create([
            'pith_production_id' => $this->production->id,
            'pith_location_id' => $location->id,
            'date' => '2026-10-01',
            'cft_offloaded' => $cft,
            'is_archived' => 0,
        ]);
    }
});

/**
 * The form shape both save paths hand the trait: keyed by location id.
 *
 * @param  array<int, float>  $byLocationId
 * @return array<int, array{cft_offloaded: float, is_archived: int}>
 */
function loadsForm(array $byLocationId): array
{
    return array_map(
        fn ($cft) => ['cft_offloaded' => $cft, 'is_archived' => 0],
        $byLocationId
    );
}

it('rejects lowering produced below the offloaded total', function () {
    $error = $this->host->inventoryDistributionError(
        $this->production->id,
        loadsForm([$this->north->id => 900, $this->south->id => 1000]),
        1850
    );

    expect($error)->toContain('1900')->toContain('1850');
});

it('allows lowering produced when the loads come down with it', function () {
    // The legitimate correction: both figures move in the same submission.
    $error = $this->host->inventoryDistributionError(
        $this->production->id,
        loadsForm([$this->north->id => 900, $this->south->id => 950]),
        1850
    );

    expect($error)->toBeNull();
});

it('counts rows the submission leaves untouched', function () {
    // Only the north yard is submitted. updateInventoryLoads() does not touch the south
    // yard's 1000 cft, so judging the payload alone would read this as 100 and pass.
    $error = $this->host->inventoryDistributionError(
        $this->production->id,
        loadsForm([$this->north->id => 100]),
        500
    );

    expect($error)->not->toBeNull();
    expect($this->host->projectedInventoryTotal($this->production->id, loadsForm([$this->north->id => 100])))
        ->toBe(1100.0);
});

it('treats an empty submission as the full persisted total', function () {
    // The session-loss case: the form arrives with no loads at all. Summing the payload
    // gave zero, so every lowering passed and wrote a mismatch with a success message.
    expect($this->host->projectedInventoryTotal($this->production->id, []))->toBe(1900.0);

    expect($this->host->inventoryDistributionError($this->production->id, [], 1850))
        ->not->toBeNull();
});

it('leaves a conflicting save with the database unchanged', function () {
    // The end state that matters: after a rejected edit, produced and offloaded still agree.
    $error = $this->host->inventoryDistributionError(
        $this->production->id,
        loadsForm([$this->north->id => 900, $this->south->id => 1000]),
        1850
    );

    expect($error)->not->toBeNull();

    $this->production->refresh();

    expect((float) $this->production->cft_produced)->toBe(1900.0)
        ->and((float) PithInventory::where('pith_production_id', $this->production->id)->sum('cft_offloaded'))
        ->toBe(1900.0);
});

it('accepts a distribution that exactly matches production', function () {
    $error = $this->host->inventoryDistributionError(
        $this->production->id,
        loadsForm([$this->north->id => 900, $this->south->id => 1000]),
        1900
    );

    expect($error)->toBeNull();
});

it('sums only the submitted loads for a production being created', function () {
    // No persisted rows to carry forward, and no production id to query by.
    expect($this->host->projectedInventoryTotal(null, loadsForm([$this->north->id => 300])))
        ->toBe(300.0);

    expect($this->host->inventoryDistributionError(null, loadsForm([$this->north->id => 300]), 200))
        ->not->toBeNull();
});

it('keeps an archived row in the total rather than dropping it', function () {
    // Archiving marks pith consumed later, not never offloaded. updateInventoryLoads()
    // skips archived entries, so the persisted row stands and still counts.
    PithInventory::where('pith_production_id', $this->production->id)
        ->where('pith_location_id', $this->south->id)
        ->update(['is_archived' => 1]);

    $submitted = [
        $this->north->id => ['cft_offloaded' => 900, 'is_archived' => 0],
        $this->south->id => ['cft_offloaded' => 0, 'is_archived' => 1],
    ];

    expect($this->host->projectedInventoryTotal($this->production->id, $submitted))->toBe(1900.0);
});
