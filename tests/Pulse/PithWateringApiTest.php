<?php

use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithStockDepletion;
use App\Models\Pulse\PithWatering;
use App\Services\NotificationSenderService;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\RbacFixtures;

/**
 * Pith watering API used by the mobile app: the /pith/watering/* CRUD endpoints, the watering
 * fields on the inventory, location and depletion-history reads, and archiving a batch's
 * waterings when its location stock is closed.
 */
beforeEach(function () {
    $this->teamId = RbacFixtures::team('Pith Watering');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);

    $this->location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Yard A',
        'is_active' => 1,
    ]);

    $this->otherTeamId = RbacFixtures::team('Pith Watering Other');
    $this->otherLocation = PithLocation::create([
        'team_id' => $this->otherTeamId,
        'name' => 'Yard Z',
        'is_active' => 1,
    ]);

    $this->mock(NotificationSenderService::class, fn ($mock) => $mock->shouldReceive('notifyTeamUsers'));
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

function logWatering(PithLocation $location, string $date, int $litres, ?int $depletionId = null): PithWatering
{
    return PithWatering::create([
        'pith_location_id' => $location->id,
        'pith_stock_depletion_id' => $depletionId,
        'date' => $date,
        'litres' => $litres,
    ]);
}

function addInwardLoad(PithLocation $location, float $cft): void
{
    PithInventory::create([
        'pith_location_id' => $location->id,
        'date' => now()->toDateString(),
        'cft_offloaded' => $cft,
        'taken_cft_calculated' => 0,
        'is_archived' => 0,
    ]);
}

function closedBatchFor(PithLocation $location): PithStockDepletion
{
    return PithStockDepletion::create([
        'pith_location_id' => $location->id,
        'depletion_date' => now()->toDateString(),
        'total_cft_offloaded' => 0,
        'total_cft_taken_calculated' => 0,
    ]);
}

// ── Store / update / delete ──────────────────────────────────────────────────

it('stores a watering in the current batch', function () {
    Sanctum::actingAs($this->users['supervisor']);

    $response = $this->postJson('/api/pith/watering/store', [
        'pith_location_id' => $this->location->id,
        'date' => '2026-09-20',
        'litres' => 1200,
    ]);

    $response->assertCreated()
        ->assertJsonPath('watering.date', '2026-09-20')
        ->assertJsonPath('watering.litres', 1200);

    $watering = PithWatering::findOrFail($response->json('watering.id'));

    expect($watering->pith_location_id)->toBe($this->location->id)
        ->and($watering->pith_stock_depletion_id)->toBeNull();
});

it('accepts the litres bounds', function (int $litres) {
    Sanctum::actingAs($this->users['supervisor']);

    $this->postJson('/api/pith/watering/store', [
        'pith_location_id' => $this->location->id,
        'date' => '2026-09-20',
        'litres' => $litres,
    ])->assertCreated();
})->with([1, 999999]);

it('rejects invalid watering input', function (array $override, string $errorKey) {
    Sanctum::actingAs($this->users['supervisor']);

    $payload = array_merge([
        'pith_location_id' => $this->location->id,
        'date' => '2026-09-20',
        'litres' => 500,
    ], $override);

    $this->postJson('/api/pith/watering/store', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect(PithWatering::where('pith_location_id', $this->location->id)->count())->toBe(0);
})->with([
    'zero litres' => [['litres' => 0], 'litres'],
    'litres over the cap' => [['litres' => 1000000], 'litres'],
    'fractional litres' => [['litres' => 1.5], 'litres'],
    'missing litres' => [['litres' => null], 'litres'],
    'missing date' => [['date' => null], 'date'],
    'badly formatted date' => [['date' => '20-09-2026'], 'date'],
    'missing location' => [['pith_location_id' => null], 'pith_location_id'],
]);

it('rejects a watering for another factory\'s location', function () {
    Sanctum::actingAs($this->users['supervisor']);

    $this->postJson('/api/pith/watering/store', [
        'pith_location_id' => $this->otherLocation->id,
        'date' => '2026-09-20',
        'litres' => 500,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pith_location_id']);

    expect(PithWatering::where('pith_location_id', $this->otherLocation->id)->count())->toBe(0);
});

it('updates a current-batch watering', function () {
    Sanctum::actingAs($this->users['supervisor']);
    $watering = logWatering($this->location, '2026-09-20', 500);

    $this->putJson("/api/pith/watering/update/{$watering->id}", [
        'date' => '2026-09-21',
        'litres' => 750,
    ])
        ->assertOk()
        ->assertJsonPath('watering.date', '2026-09-21')
        ->assertJsonPath('watering.litres', 750);

    $watering->refresh();

    expect($watering->date->format('Y-m-d'))->toBe('2026-09-21')
        ->and($watering->litres)->toBe(750);
});

it('validates watering updates', function () {
    Sanctum::actingAs($this->users['supervisor']);
    $watering = logWatering($this->location, '2026-09-20', 500);

    $this->putJson("/api/pith/watering/update/{$watering->id}", ['date' => '2026-09-21', 'litres' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['litres']);

    expect($watering->refresh()->litres)->toBe(500);
});

it('deletes a current-batch watering', function () {
    Sanctum::actingAs($this->users['admin']);
    $watering = logWatering($this->location, '2026-09-20', 500);

    $this->deleteJson("/api/pith/watering/delete/{$watering->id}")->assertOk();

    expect(PithWatering::find($watering->id))->toBeNull();
});

it('hides another factory\'s watering from update and delete', function () {
    Sanctum::actingAs($this->users['admin']);
    $watering = logWatering($this->otherLocation, '2026-09-20', 500);

    $this->putJson("/api/pith/watering/update/{$watering->id}", ['date' => '2026-09-21', 'litres' => 750])
        ->assertNotFound();
    $this->deleteJson("/api/pith/watering/delete/{$watering->id}")
        ->assertNotFound();

    expect($watering->refresh()->litres)->toBe(500);
});

it('refuses to change a closed batch\'s watering', function () {
    Sanctum::actingAs($this->users['admin']);
    $watering = logWatering($this->location, '2026-09-20', 500, closedBatchFor($this->location)->id);

    $this->putJson("/api/pith/watering/update/{$watering->id}", ['date' => '2026-09-21', 'litres' => 750])
        ->assertUnprocessable()
        ->assertJsonPath('message', "This watering belongs to a closed batch and can't be changed.");
    $this->deleteJson("/api/pith/watering/delete/{$watering->id}")
        ->assertUnprocessable();

    expect($watering->refresh()->litres)->toBe(500);
});

// ── Permissions ──────────────────────────────────────────────────────────────

it('lets staff log watering but not edit or delete it', function () {
    Sanctum::actingAs($this->users['staff']);
    $watering = logWatering($this->location, '2026-09-20', 500);

    $this->postJson('/api/pith/watering/store', [
        'pith_location_id' => $this->location->id,
        'date' => '2026-09-20',
        'litres' => 500,
    ])->assertCreated();

    $this->putJson("/api/pith/watering/update/{$watering->id}", ['date' => '2026-09-21', 'litres' => 750])
        ->assertForbidden();
    $this->deleteJson("/api/pith/watering/delete/{$watering->id}")
        ->assertForbidden();
});

it('keeps readers out of watering writes', function () {
    Sanctum::actingAs($this->users['reader']);

    $this->postJson('/api/pith/watering/store', [
        'pith_location_id' => $this->location->id,
        'date' => '2026-09-20',
        'litres' => 500,
    ])->assertForbidden();
});

it('blocks every watering endpoint when pith inventory view is removed', function () {
    RbacFixtures::revokePrefixes($this->teamId, 'admin', ['pith_inventory:']);
    Sanctum::actingAs($this->users['admin']);
    $watering = logWatering($this->location, '2026-09-20', 500);

    $this->postJson('/api/pith/watering/store', [
        'pith_location_id' => $this->location->id,
        'date' => '2026-09-20',
        'litres' => 500,
    ])->assertForbidden();
    $this->putJson("/api/pith/watering/update/{$watering->id}", ['date' => '2026-09-21', 'litres' => 750])
        ->assertForbidden();
    $this->deleteJson("/api/pith/watering/delete/{$watering->id}")
        ->assertForbidden();
});

it('seeds watering capabilities for new teams by role', function () {
    $permissions = fn (string $role) => array_values(array_filter(
        TeamRolePermissions::roleForTeam($this->teamId, $role)['permissions'] ?? [],
        fn (string $capability) => str_starts_with($capability, 'pith_watering:')
    ));

    expect($permissions('reader'))->toBe([])
        ->and($permissions('staff'))->toBe(['pith_watering:create'])
        ->and($permissions('supervisor'))->toBe(['pith_watering:create', 'pith_watering:edit'])
        ->and($permissions('admin'))->toBe(['pith_watering:create', 'pith_watering:delete', 'pith_watering:edit'])
        ->and($permissions('owner'))->toBe(['pith_watering:create', 'pith_watering:delete', 'pith_watering:edit']);
});

it('backfills watering capabilities onto existing roles that can view pith inventory', function () {
    RbacFixtures::setPermissions($this->teamId, 'staff', ['pith_inventory:view', 'pith_inventory_outward:view']);
    RbacFixtures::setPermissions($this->teamId, 'supervisor', ['pith_production:view']);
    RbacFixtures::setPermissions($this->teamId, 'admin', ['pith_inventory:view']);

    $stored = fn (string $role) => DB::table('pulse_team_roles')
        ->where('team_id', $this->teamId)
        ->where('key', $role)
        ->value('permissions');

    $migration = require database_path('migrations/2026_09_24_173156_backfill_pith_watering_capabilities.php');
    $migration->up();

    expect(json_decode($stored('staff'), true))->toBe(['pith_inventory:view', 'pith_inventory_outward:view', 'pith_watering:create'])
        ->and(json_decode($stored('supervisor'), true))->toBe(['pith_production:view'])
        ->and(json_decode($stored('admin'), true))->toBe(['pith_inventory:view', 'pith_watering:create', 'pith_watering:delete', 'pith_watering:edit']);

    $migration->down();

    expect(json_decode($stored('staff'), true))->toBe(['pith_inventory:view', 'pith_inventory_outward:view'])
        ->and(json_decode($stored('admin'), true))->toBe(['pith_inventory:view']);
});

// ── Reads ────────────────────────────────────────────────────────────────────

it('adds current-batch watering totals to the inventory list', function () {
    Sanctum::actingAs($this->users['reader']);
    addInwardLoad($this->location, 100);
    logWatering($this->location, '2026-09-10', 500);
    logWatering($this->location, '2026-09-18', 700);
    logWatering($this->location, '2026-09-18', 300);
    logWatering($this->location, '2026-09-25', 9999, closedBatchFor($this->location)->id);

    $row = collect($this->getJson('/api/pith/inventory')->assertOk()->json())
        ->firstWhere('location_id', $this->location->id);

    expect($row['totalWaterLitres'])->toBe(1500)
        ->and($row['waterCycles'])->toBe(3)
        ->and($row['lastWateredDate'])->toBe('2026-09-18')
        ->and($row['lastWateredLitres'])->toBe(300);
});

it('reports an unwatered location with empty watering fields', function () {
    Sanctum::actingAs($this->users['reader']);

    $row = collect($this->getJson('/api/pith/inventory')->assertOk()->json())
        ->firstWhere('location_id', $this->location->id);

    expect($row)->toHaveKeys(['totalWaterLitres', 'waterCycles', 'lastWateredDate', 'lastWateredLitres'])
        ->and($row['totalWaterLitres'])->toBe(0)
        ->and($row['waterCycles'])->toBe(0)
        ->and($row['lastWateredDate'])->toBeNull()
        ->and($row['lastWateredLitres'])->toBeNull();
});

it('reports a negative netStock once overdrawn, while remainingStock stays floored at 0', function () {
    Sanctum::actingAs($this->users['reader']);
    addInwardLoad($this->location, 100);
    PithInventory::create([
        'pith_location_id' => $this->location->id,
        'date' => now()->toDateString(),
        'taken_cft_calculated' => 250,
        'is_archived' => 0,
    ]);

    $row = collect($this->getJson('/api/pith/inventory')->assertOk()->json())
        ->firstWhere('location_id', $this->location->id);

    expect($row['remainingStock'])->toBe(0)
        ->and($row['netStock'])->toBe(-150);
});

it('excludes a deactivated location whose loads have all been archived', function () {
    Sanctum::actingAs($this->users['reader']);

    $emptied = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Emptied Inactive',
        'is_active' => 0,
    ]);

    PithInventory::create([
        'pith_location_id' => $emptied->id,
        'date' => now()->subDay()->toDateString(),
        'cft_offloaded' => 500,
        'taken_cft_calculated' => 500,
        'is_archived' => 1,
    ]);

    $locationIds = collect($this->getJson('/api/pith/inventory')->assertOk()->json())
        ->pluck('location_id');

    expect($locationIds)->not->toContain($emptied->id);
});

it('includes a deactivated location that still holds live stock', function () {
    Sanctum::actingAs($this->users['reader']);

    $stocked = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Stocked Inactive',
        'is_active' => 0,
    ]);

    addInwardLoad($stocked, 200);

    $row = collect($this->getJson('/api/pith/inventory')->assertOk()->json())
        ->firstWhere('location_id', $stocked->id);

    expect($row)->not->toBeNull()
        ->and($row['is_active'])->toBeFalse()
        ->and($row['totalLoadsOffloaded'])->toBe(200);
});

it('lists the current batch\'s waterings on the location', function () {
    Sanctum::actingAs($this->users['reader']);
    addInwardLoad($this->location, 100);
    $older = logWatering($this->location, '2026-09-10', 500);
    $newer = logWatering($this->location, '2026-09-18', 700);
    logWatering($this->location, '2026-09-25', 9999, closedBatchFor($this->location)->id);

    $this->getJson("/api/pith/location/{$this->location->id}")
        ->assertOk()
        ->assertJsonPath('totalWaterLitres', 1200)
        ->assertJsonPath('waterCycles', 2)
        ->assertJsonPath('waterings', [
            ['id' => $newer->id, 'date' => '2026-09-18', 'litres' => 700],
            ['id' => $older->id, 'date' => '2026-09-10', 'litres' => 500],
        ]);
});

it('hides another factory\'s location and history', function () {
    Sanctum::actingAs($this->users['admin']);

    $this->getJson("/api/pith/location/{$this->otherLocation->id}")->assertNotFound();
    $this->getJson("/api/pith/stockDepletionHistory/{$this->otherLocation->id}")->assertNotFound();
});

// ── Closing a batch ──────────────────────────────────────────────────────────

it('archives the batch\'s waterings when the location stock is closed', function () {
    Sanctum::actingAs($this->users['supervisor']);
    addInwardLoad($this->location, 100);
    logWatering($this->location, '2026-09-10', 500);
    logWatering($this->location, '2026-09-18', 700);

    $this->postJson("/api/pith/closelocationstock/{$this->location->id}")->assertOk();

    $depletion = PithStockDepletion::where('pith_location_id', $this->location->id)->sole();

    expect(PithWatering::where('pith_location_id', $this->location->id)->pluck('pith_stock_depletion_id')->unique()->all())
        ->toBe([$depletion->id]);

    $this->getJson("/api/pith/location/{$this->location->id}")
        ->assertOk()
        ->assertJsonPath('totalWaterLitres', 0)
        ->assertJsonPath('waterCycles', 0)
        ->assertJsonPath('waterings', []);

    $this->getJson("/api/pith/stockDepletionHistory/{$this->location->id}")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.total_water_litres', 1200)
        ->assertJsonPath('0.water_cycles', 2)
        ->assertJsonPath('0.waterings.0.date', '2026-09-18')
        ->assertJsonPath('0.waterings.1.date', '2026-09-10');
});

it('keeps two same-day closes as separate batches in the history', function () {
    Sanctum::actingAs($this->users['supervisor']);

    addInwardLoad($this->location, 100);
    logWatering($this->location, '2026-09-10', 100);
    $this->postJson("/api/pith/closelocationstock/{$this->location->id}")->assertOk();

    addInwardLoad($this->location, 50);
    logWatering($this->location, '2026-09-11', 200);
    logWatering($this->location, '2026-09-12', 300);
    $this->postJson("/api/pith/closelocationstock/{$this->location->id}")->assertOk();

    $this->getJson("/api/pith/stockDepletionHistory/{$this->location->id}")
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.total_cft_offloaded', 50)
        ->assertJsonPath('0.total_water_litres', 500)
        ->assertJsonPath('0.water_cycles', 2)
        ->assertJsonPath('1.total_cft_offloaded', 100)
        ->assertJsonPath('1.total_water_litres', 100)
        ->assertJsonPath('1.water_cycles', 1);
});
