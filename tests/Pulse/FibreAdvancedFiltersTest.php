<?php

use App\Livewire\Pulse\MiniBale\MiniBaleProductionList;
use App\Models\Pulse\MiniBaleProduction;
use App\Models\User;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Fibre Adv Filters '.uniqid());
    $this->user = User::factory()->create(['is_superadmin' => 1, 'current_team_id' => $this->teamId]);
    $this->actingAs($this->user);

    MiniBaleProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-20',
        'start_time' => '2026-08-20 08:00:00',
        'end_time' => '2026-08-20 18:00:00',
        'bales_expected' => 100,
        'bales_produced' => 150,
        'bales_in_stock' => 80,
    ]);
    MiniBaleProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-19',
        'start_time' => '2026-08-19 08:00:00',
        'end_time' => '2026-08-19 18:00:00',
        'bales_expected' => 100,
        'bales_produced' => 40,
        'bales_in_stock' => 10,
    ]);
});

it('shows Filters button and opens the popover', function () {
    // Header (with Filters) lives in an x-slot rendered by the layout, so
    // Livewire::test HTML only sees the body; assert open state instead.
    Livewire::test(MiniBaleProductionList::class)
        ->assertSet('filtersOpen', false)
        ->call('toggleFilters')
        ->assertSet('filtersOpen', true)
        ->call('closeFilters')
        ->assertSet('filtersOpen', false);
});

it('applies output range and shows chips', function () {
    Livewire::test(MiniBaleProductionList::class)
        ->set('draftOutMin', '100')
        ->set('draftOutMax', '200')
        ->call('applyFilters')
        ->assertSet('outMin', '100')
        ->assertSee('Filtered by')
        ->assertSee('Output:');
});

it('clears all filters and chips', function () {
    Livewire::test(MiniBaleProductionList::class)
        ->set('draftOutMin', '100')
        ->call('applyFilters')
        ->assertSee('Filtered by')
        ->call('clearAllFilters')
        ->assertSet('outMin', '')
        ->assertDontSee('Filtered by');
});

it('applies sold range and shows chips', function () {
    Livewire::test(MiniBaleProductionList::class)
        ->set('draftSoldMin', '50')
        ->set('draftSoldMax', '100')
        ->call('applyFilters')
        ->assertSet('soldMin', '50')
        ->assertSee('Filtered by')
        ->assertSee('Sold:');
});

it('syncs machine pill strip to applied machineIds', function () {
    Livewire::test(MiniBaleProductionList::class)
        ->call('filterByMachine', 42)
        ->assertSet('machineIds', [42])
        ->call('filterByMachine', null)
        ->assertSet('machineIds', []);
});
