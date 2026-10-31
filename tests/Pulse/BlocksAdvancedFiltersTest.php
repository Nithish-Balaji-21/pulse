<?php

use App\Livewire\Pulse\PithBlocks\PithBlocksProductionList;
use App\Models\Pulse\PithBlocksProduction;
use App\Models\User;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Blocks Adv Filters '.uniqid());
    $this->user = User::factory()->create(['is_superadmin' => 1, 'current_team_id' => $this->teamId]);
    $this->actingAs($this->user);

    PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-20',
        'start_time' => '2026-08-20 08:00:00',
        'end_time' => '2026-08-20 18:00:00',
        'blocks_expected' => 400,
        'blocks_produced' => 520,
        'blocks_in_stock' => 200,
    ]);
    PithBlocksProduction::create([
        'team_id' => $this->teamId,
        'shift_date' => '2026-08-19',
        'start_time' => '2026-08-19 08:00:00',
        'end_time' => '2026-08-19 18:00:00',
        'blocks_expected' => 400,
        'blocks_produced' => 100,
        'blocks_in_stock' => 50,
    ]);
});

it('shows Filters button and opens the popover', function () {
    // Header (with Filters) lives in an x-slot rendered by the layout, so
    // Livewire::test HTML only sees the body; assert open state instead.
    Livewire::test(PithBlocksProductionList::class)
        ->assertSet('filtersOpen', false)
        ->call('toggleFilters')
        ->assertSet('filtersOpen', true)
        ->call('closeFilters')
        ->assertSet('filtersOpen', false);
});

it('applies output range and shows chips', function () {
    Livewire::test(PithBlocksProductionList::class)
        ->set('draftOutMin', '400')
        ->set('draftOutMax', '600')
        ->call('applyFilters')
        ->assertSet('outMin', '400')
        ->assertSee('Filtered by')
        ->assertSee('Output:');
});

it('clears all filters and chips', function () {
    Livewire::test(PithBlocksProductionList::class)
        ->set('draftOutMin', '400')
        ->call('applyFilters')
        ->assertSee('Filtered by')
        ->call('clearAllFilters')
        ->assertSet('outMin', '')
        ->assertDontSee('Filtered by');
});

it('applies sold range and shows chips', function () {
    Livewire::test(PithBlocksProductionList::class)
        ->set('draftSoldMin', '10')
        ->set('draftSoldMax', '50')
        ->call('applyFilters')
        ->assertSet('soldMin', '10')
        ->assertSee('Filtered by')
        ->assertSee('Sold:');
});
