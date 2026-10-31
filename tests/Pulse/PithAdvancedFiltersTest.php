<?php

use App\Livewire\Pulse\Pith\PithProductionList;
use App\Models\Pulse\PithProduction;
use App\Models\User;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Pith Adv Filters '.uniqid());
    $this->user = User::factory()->create(['is_superadmin' => 1, 'current_team_id' => $this->teamId]);
    $this->actingAs($this->user);

    $this->shift1 = DB::table('pulse_shifts')->insertGetId([
        'team_id' => $this->teamId,
        'name' => 'Shift 1',
        'start_time' => '08:00:00',
        'end_time' => '18:00:00',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->shift2 = DB::table('pulse_shifts')->insertGetId([
        'team_id' => $this->teamId,
        'name' => 'Shift 2',
        'start_time' => '18:00:00',
        'end_time' => '08:00:00',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    PithProduction::create([
        'team_id' => $this->teamId,
        'shift_id' => $this->shift1,
        'shift_date' => '2026-08-20',
        'shift_name' => 'Shift 1',
        'start_time' => '2026-08-20 08:00:00',
        'end_time' => '2026-08-20 18:00:00',
        'cft_expected' => 1000,
        'cft_produced' => 1200,
        'eb_price' => 4800,
        'load_hrs' => 8,
    ]);
    PithProduction::create([
        'team_id' => $this->teamId,
        'shift_id' => $this->shift2,
        'shift_date' => '2026-08-19',
        'shift_name' => 'Shift 2',
        'start_time' => '2026-08-19 18:00:00',
        'end_time' => '2026-08-20 08:00:00',
        'cft_expected' => 800,
        'cft_produced' => 500,
        'eb_price' => 0,
        'load_hrs' => null,
    ]);
});

it('applies shift filter from draft and shows chips', function () {
    Livewire::test(PithProductionList::class)
        ->call('toggleFilters')
        ->assertSet('filtersOpen', true)
        ->call('toggleDraftShift', $this->shift1)
        ->call('applyFilters')
        ->assertSet('filtersOpen', false)
        ->assertSet('shiftIds', [$this->shift1])
        ->assertSee('Filtered by')
        ->assertSee('Shift:')
        ->assertSee('Shift 1');
});

it('filters output range against the database', function () {
    $component = Livewire::test(PithProductionList::class)
        ->set('draftOutMin', '1000')
        ->set('draftOutMax', '2000')
        ->call('applyFilters');

    expect($component->get('outMin'))->toBe('1000');
    $component
        ->assertSee('1,200')
        ->assertSee('Output:');
});

it('clears all filters and chips', function () {
    Livewire::test(PithProductionList::class)
        ->set('draftShiftIds', [$this->shift1])
        ->call('applyFilters')
        ->assertSee('Filtered by')
        ->call('clearAllFilters')
        ->assertSet('shiftIds', [])
        ->assertDontSee('Filtered by');
});

it('shows Filters button and opens the popover', function () {
    Livewire::test(PithProductionList::class)
        ->assertSee('Filters')
        ->assertDontSee('Advanced search')
        ->call('toggleFilters')
        ->assertSee('Advanced search');
});
