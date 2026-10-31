<?php

use App\Livewire\Pulse\Pith\PithProductionList;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * Readers must not reach pith inventory.
 *
 * The blade hides Edit and Inventory from them, but Livewire actions are callable
 * endpoints in their own right — hiding a button authorizes nothing.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Pith Inventory Factory '.uniqid());

    $this->reader = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    $this->staff = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        ['team_id' => $this->teamId, 'user_id' => $this->reader->id, 'role' => 'reader'],
        ['team_id' => $this->teamId, 'user_id' => $this->staff->id, 'role' => 'staff'],
    ]);

    app()->instance('current.team', Team::find($this->teamId));
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

/**
 * State, not the flash message: flash data targets the following request, so it
 * reads back as null inside a Livewire call.
 */
it('refuses to open the inventory modal for a reader', function () {
    $this->actingAs($this->reader);

    Livewire::test(PithProductionList::class)
        ->call('openInventoryModal', 1, 'Shift A')
        ->assertSet('showInventoryModal', false)
        // The guard returns before this is assigned, so an untouched value proves
        // the method stopped at authorization rather than further downstream.
        ->assertSet('productionId', null);
});

it('refuses an inventory write from a reader', function () {
    $this->actingAs($this->reader);

    $before = DB::table('pulse_pith_inventory')->count();

    Livewire::test(PithProductionList::class)->call('updateLoads');

    expect(DB::table('pulse_pith_inventory')->count())->toBe($before);
});

it('puts a reader below the inventory gate and staff above it', function () {
    $this->actingAs($this->reader);
    expect(Gate::allows('manage-records'))->toBeFalse();

    $this->actingAs($this->staff);
    expect(Gate::allows('manage-records'))->toBeTrue();
});

/**
 * The blade conditions behind the Edit and Inventory buttons in
 * Pulse/livewire/pith/partials/detailed.blade.php.
 */
it('hides Edit and Inventory from a reader in the browsed factory', function () {
    expect($this->reader->canManageRecords())->toBeFalse()
        ->and($this->staff->canManageRecords())->toBeTrue();
});
