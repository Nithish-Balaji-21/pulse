<?php

use App\Http\Controllers\Pulse\Api\PithProductionInventoryController;
use App\Livewire\Pulse\Pith\PithLocationLoads;
use App\Models\Pulse\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Team;
use App\Models\User;
use App\Services\NotificationSenderService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * Regression coverage for a double-click on "Close & Archive Stock": the first
 * call archived the loads and emailed/pushed the correct In/Out totals, but a
 * second call — finding nothing left to archive — still fired a second
 * notification with In: 0 | Out: 0. See closeLocationStock() in
 * PithProductionInventoryController.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Pith Close Idempotency Factory '.uniqid());

    $this->supervisor = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $this->teamId, 'user_id' => $this->supervisor->id, 'role' => 'supervisor',
    ]);

    app()->instance('current.team', Team::find($this->teamId));

    $this->location = PithLocation::create([
        'team_id' => $this->teamId,
        'name' => 'Yard A',
        'is_active' => 1,
    ]);

    PithInventory::create([
        'pith_location_id' => $this->location->id,
        'date' => now()->toDateString(),
        'cft_offloaded' => 100,
        'taken_cft_calculated' => 30,
        'is_archived' => 0,
    ]);

    PithInventory::create([
        'pith_location_id' => $this->location->id,
        'date' => now()->toDateString(),
        'cft_offloaded' => 50,
        'taken_cft_calculated' => 20,
        'is_archived' => 0,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('sends exactly one notification with the real totals, even when closed twice', function () {
    $this->actingAs($this->supervisor);

    $this->mock(NotificationSenderService::class, function ($mock) {
        $mock->shouldReceive('notifyTeamUsers')
            ->once()
            ->withArgs(function ($teamId, $roles, $subject, $bodyLines) {
                return $subject === 'Pith Emptied - Yard A'
                    && $bodyLines === ['In: 150 | Out: 50'];
            });
    });

    $controller = new PithProductionInventoryController;

    $first = $controller->closeLocationStock($this->location->id);
    expect($first->status())->toBe(200)
        ->and($first->getData()->message)->toBe('Location stock closed successfully.');

    // Simulates the accidental double-click: nothing left to archive this time.
    $second = $controller->closeLocationStock($this->location->id);
    expect($second->status())->toBe(200)
        ->and($second->getData()->message)->toBe('Location stock is already closed.');

    expect(PithInventory::where('pith_location_id', $this->location->id)->where('is_archived', 0)->count())->toBe(0)
        ->and(DB::table('pulse_pith_stock_depletions')->where('pith_location_id', $this->location->id)->count())->toBe(1);

    // Every archived load names the closure that archived it — the export's batch windows rely on it.
    $depletionId = DB::table('pulse_pith_stock_depletions')->where('pith_location_id', $this->location->id)->value('id');
    expect(PithInventory::where('pith_location_id', $this->location->id)->pluck('pith_stock_depletion_id')->unique()->all())
        ->toBe([$depletionId]);
});

it('closes from the panel onto the closed batch, with a way back to the new one', function () {
    $this->actingAs($this->supervisor);

    $component = Livewire::test(PithLocationLoads::class, [
        'locationId' => $this->location->id,
        'teamId' => $this->teamId,
    ])
        ->call('confirmCloseLocation')
        ->assertSet('showCloseDialog', true)
        ->call('closeLocationStock');

    $depletion = DB::table('pulse_pith_stock_depletions')->where('pith_location_id', $this->location->id)->sole();

    $component->assertSet('showCloseDialog', false)
        ->assertNoRedirect()
        ->assertDispatched('pith-inventory-refresh')
        ->assertDispatched('pi-toast', message: 'Batch 1 closed · moved to Past Batches')
        ->assertSet('viewDepletionId', $depletion->id)
        ->assertSee('VIEWING CLOSED BATCH · READ-ONLY')
        ->assertSee('Back to current batch')
        ->call('backToCurrent')
        ->assertSet('viewDepletionId', null)
        ->assertSee('Batch 2 · empty');
});
