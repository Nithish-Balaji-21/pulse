<?php

use App\Livewire\Pulse\Reports\Inventory\BalesInventoryReport;
use App\Livewire\Pulse\Reports\Inventory\BlocksInventoryReport;
use App\Livewire\Pulse\Reports\Inventory\HuskInventoryReport;
use App\Livewire\Pulse\Reports\Inventory\PithInventoryReport;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Inventory Report Component Test '.uniqid());
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
    app()->instance('current.team', Team::find($this->teamId));
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('renders pith inventory report component and handles date range update', function () {
    Livewire::test(PithInventoryReport::class)
        ->assertOk()
        ->assertSee('Raw Pith Inventory')
        ->dispatch('date-range-updated', startDate: '2026-08-01', endDate: '2026-08-07', preset: 'This Week')
        ->assertSet('startDate', '2026-08-01')
        ->assertSet('endDate', '2026-08-07');

    expect(session("global_report_start_date_{$this->teamId}"))->toBe('2026-08-01');
});

it('exports pdf and dispatches image summary card event for pith inventory', function () {
    Livewire::test(PithInventoryReport::class)
        ->call('exportPdf')
        ->assertOk();

    Livewire::test(PithInventoryReport::class)
        ->call('sendSummaryCard')
        ->assertDispatched('trigger-summary-card-export');
});

it('renders husk inventory report when pith is enabled', function () {
    Livewire::test(HuskInventoryReport::class)
        ->assertOk()
        ->assertSee('Husk Inventory');
});

it('redirects husk inventory report when pith is disabled', function () {
    $teamId = TeamFactory::create('Husk No Pith '.uniqid(), false, [
        'modules' => [
            'pith' => ['is_active' => false, 'submodules' => ['bales' => false, 'blocks' => false]],
            'husks_stock' => ['is_active' => true],
            'attendance' => ['is_active' => true],
        ],
    ]);

    $user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $teamId,
    ]);
    $this->actingAs($user);
    app()->instance('current.team', Team::with('pulse')->find($teamId));

    Livewire::test(HuskInventoryReport::class)
        ->assertRedirect('/dashboard');
});

it('renders bales inventory report component', function () {
    Livewire::test(BalesInventoryReport::class)
        ->assertOk()
        ->assertSee('Fibre Bales Inventory');
});

it('renders blocks inventory report component', function () {
    Livewire::test(BlocksInventoryReport::class)
        ->assertOk()
        ->assertSee('Pith Blocks Inventory');
});
