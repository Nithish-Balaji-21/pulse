<?php

use App\Livewire\Pulse\Pith\PithProductionList;
use App\Models\Pulse\PithProduction;
use App\Models\User;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * The Pith production column uses the same estimate-primary cell as Fibre Bales and
 * Pith Blocks: the estimate reads as the headline until an actual is entered.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Pith Output Cell '.uniqid());
    $this->user = User::factory()->create(['is_superadmin' => 1, 'current_team_id' => $this->teamId]);
    $this->actingAs($this->user);

    $this->shiftId = DB::table('pulse_shifts')->insertGetId([
        'team_id' => $this->teamId,
        'name' => 'Shift 1',
        'start_time' => '08:00:00',
        'end_time' => '18:00:00',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function pithRun(int $teamId, int $shiftId, string $date, int $expected, int $produced = 0): PithProduction
{
    return PithProduction::create([
        'team_id' => $teamId,
        'shift_id' => $shiftId,
        'shift_date' => $date,
        'shift_name' => 'Shift 1',
        'start_time' => $date.' 08:00:00',
        'end_time' => $date.' 18:00:00',
        'cft_expected' => $expected,
        'cft_produced' => $produced,
    ]);
}

it('shows the estimate with an est tag when no actual was entered', function () {
    pithRun($this->teamId, $this->shiftId, '2026-07-28', 1288);

    Livewire::test(PithProductionList::class)
        ->assertSee('1,288')
        ->assertSee('est')
        ->assertDontSee('Estimated: 1,288');
});

it('shows the actual against the estimate once one is entered', function () {
    pithRun($this->teamId, $this->shiftId, '2026-07-28', 1000, 900);

    Livewire::test(PithProductionList::class)
        ->assertSee('900')
        ->assertSee('of 1,000 est')
        ->assertSee('-10%');
});

it('renames the column header to match the other modules', function () {
    pithRun($this->teamId, $this->shiftId, '2026-07-28', 500);

    Livewire::test(PithProductionList::class)
        ->assertSee('OUTPUT (CFT)')
        ->assertDontSee('PRODUCTION (CFT)');
});
