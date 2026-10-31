<?php

use App\Livewire\Pulse\ManageShifts;
use App\Models\Pulse\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

function pulseEditorTeam(string $anchor = '08:00:00'): int
{
    $teamId = TeamFactory::create('Editor Test Factory '.uniqid());

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId,
        'module' => 'shifts',
        'key' => Shift::ANCHOR_SETTING_KEY,
        'text' => 'Shift day anchor (start of the 24-hour production day)',
        'value' => $anchor,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $teamId;
}

beforeEach(function () {
    Shift::forgetAnchorCache();

    $this->teamId = pulseEditorTeam('06:00:00');
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);
});

it('renders with the team anchor and applies presets measured from it', function () {
    Livewire::test(ManageShifts::class)
        ->assertSet('anchor', '06:00:00')
        ->assertSee('Shift day runs')
        ->call('applyPreset', 2)
        ->assertSet('draft.0.start_time', '06:00')
        ->assertSet('draft.0.end_time', '18:00')
        ->assertSet('draft.1.start_time', '18:00')
        ->assertSet('draft.1.end_time', '06:00')
        ->call('save');

    expect(Shift::activeForTeam($this->teamId)->count())->toBe(2);
});

it('flags an anchor-crossing shift live and refuses to save it', function () {
    Livewire::test(ManageShifts::class)
        ->call('applyPreset', 1)
        ->set('draft.0.start_time', '05:00')
        ->assertSet('draftErrors.0', fn ($message) => $message !== null)
        ->call('save');

    expect(Shift::activeForTeam($this->teamId)->count())->toBe(0);
});

it('defaults a new shift to start at the max existing end offset', function () {
    Livewire::test(ManageShifts::class)
        ->call('applyPreset', 1)   // 06:00 → 16:00 (10h from the anchor)
        ->call('addShift')
        ->assertSet('draft.1.start_time', '16:00');
});

it('flags the draft against the day start being edited, not the saved one', function () {
    Livewire::test(ManageShifts::class)
        ->call('applyPreset', 2)   // 06:00-18:00, 18:00-06:00 under the saved 06:00 anchor
        ->call('save')
        ->set('anchorInput', '07:00')
        ->assertSet('draftErrors', fn ($errors) => $errors !== [])
        ->assertSet('anchor', '06:00:00');
});

it('moves the day start and the shifts in one save', function () {
    // AVM Fibres: a 07:00-anchored round-the-clock shift becoming an 08:00-anchored
    // day/night pair. Neither half is legal until the other lands, so both go at once.
    $teamId = pulseEditorTeam('07:00:00');
    $this->user->forceFill(['current_team_id' => $teamId])->save();

    Shift::create([
        'team_id' => $teamId,
        'name' => 'General',
        'start_time' => '07:00:00',
        'end_time' => '07:00:00',
        'is_active' => 1,
    ]);

    Livewire::test(ManageShifts::class)
        ->assertSet('anchor', '07:00:00')
        ->set('anchorInput', '08:00')
        ->set('draft.0.name', 'Day')
        ->set('draft.0.start_time', '08:00')
        ->set('draft.0.end_time', '20:00')
        ->call('addShift')
        ->set('draft.1.name', 'Night')
        ->set('draft.1.start_time', '20:00')
        ->set('draft.1.end_time', '08:00')
        ->assertSet('draftErrors', [])
        ->assertSet('scheduleError', null)
        ->call('save')
        ->assertSet('anchor', '08:00:00')
        ->assertSet('scheduleError', null);

    Shift::forgetAnchorCache($teamId);
    expect(Shift::anchorForTeam($teamId))->toBe('08:00:00');

    $shifts = Shift::activeForTeam($teamId)->orderedByOffset('08:00:00')->get();
    expect($shifts->pluck('name')->all())->toBe(['Day', 'Night'])
        ->and($shifts->map(fn ($shift) => $shift->start_time.'-'.$shift->end_time)->all())
        ->toBe(['08:00:00-20:00:00', '20:00:00-08:00:00']);
});

it('leaves the day start alone when only the shifts change', function () {
    Livewire::test(ManageShifts::class)
        ->call('applyPreset', 2)
        ->call('save')
        ->assertSet('anchor', '06:00:00');

    Shift::forgetAnchorCache($this->teamId);
    expect(Shift::anchorForTeam($this->teamId))->toBe('06:00:00');
});
