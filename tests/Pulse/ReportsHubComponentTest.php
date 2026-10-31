<?php

use App\Livewire\Pulse\Reports\ReportsHub;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\RbacFixtures;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Reports Hub Test '.uniqid());
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

it('renders reports hub without production and sets global session date range', function () {
    // The page title and its tab bar come from the shared report header, which fills the
    // layout's header slot rather than the component's own markup — see the route test below
    // for those. What is asserted here is what this component actually renders.
    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertSee('INVENTORY')
        ->assertSee('Husk Inventory')
        ->assertDontSeeHtml('>PRODUCTION</')
        ->dispatch('date-range-updated', startDate: '2026-08-01', endDate: '2026-08-07', preset: 'This Week')
        ->assertSet('startDate', '2026-08-01')
        ->assertSet('endDate', '2026-08-07');

    expect(session("global_report_start_date_{$this->teamId}"))->toBe('2026-08-01');
});

/**
 * The hub's title and tab bar are an <x-slot name="header"> on the shared report header, so
 * they only exist once the component is rendered through its layout. Asserting them on an
 * isolated Livewire render passes over the layout entirely and cannot see them.
 */
it('renders the reports hub page with its header through the layout', function () {
    $this->get(route('reports.hub', ['team' => $this->teamId]))
        ->assertOk()
        ->assertSee('Reports')
        ->assertSee('INVENTORY');
});

it('shows husk inventory on hub when pith is on even if husks_stock flag is off', function () {
    $teamId = TeamFactory::create('Reports Hub Pith Implies Husk '.uniqid(), true, [
        'modules' => [
            'pith' => ['is_active' => true, 'submodules' => ['bales' => false, 'blocks' => false]],
            'husks_stock' => ['is_active' => false],
            'attendance' => ['is_active' => false],
        ],
    ]);

    $user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $teamId,
    ]);
    $this->actingAs($user);
    app()->instance('current.team', Team::with('pulse')->find($teamId));

    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertSee('Husk Inventory')
        ->assertSee('Raw Pith Inventory');
});

it('shows you dont have access when hub has no visible report cards', function () {
    $teamId = TeamFactory::create('Reports Hub No Access '.uniqid(), false, [
        'modules' => [
            'pith' => ['is_active' => false, 'submodules' => ['bales' => false, 'blocks' => false]],
            'husks_stock' => ['is_active' => false],
            'attendance' => ['is_active' => true],
        ],
    ]);

    $user = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $teamId,
        'user_id' => $user->id,
        'role' => 'reader',
    ]);

    $this->actingAs($user);
    app()->instance('current.team', Team::with('pulse')->find($teamId));

    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertSee('You don’t have access')
        ->assertDontSee('STAFF ATTENDANCE')
        ->assertDontSee('Raw Pith Inventory');
});

it('hides pith and husk inventory cards from reader but shows them to supervisor', function () {
    $teamId = TeamFactory::create('Reports Hub RBAC '.uniqid(), true, [
        'modules' => [
            'pith' => ['is_active' => true, 'submodules' => ['bales' => false, 'blocks' => false]],
            'husks_stock' => ['is_active' => true],
            'attendance' => ['is_active' => false],
        ],
    ]);

    $reader = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId, 'user_id' => $reader->id, 'role' => 'reader',
    ]);

    $supervisor = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId, 'user_id' => $supervisor->id, 'role' => 'supervisor',
    ]);

    app()->instance('current.team', Team::with('pulse')->find($teamId));

    $this->actingAs($reader);
    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertSee('You don’t have access')
        ->assertDontSee('Raw Pith Inventory')
        ->assertDontSee('Husk Inventory');

    $this->actingAs($supervisor);
    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertSee('Raw Pith Inventory')
        ->assertSee('Husk Inventory')
        ->assertDontSee('You don’t have access');
});

it('shows fibre bales and pith blocks cards to supervisor but not reader', function () {
    $teamId = TeamFactory::create('Reports Hub Bales Blocks '.uniqid(), true, [
        'modules' => [
            'pith' => ['is_active' => true, 'submodules' => ['bales' => true, 'blocks' => true]],
            'husks_stock' => ['is_active' => false],
            'attendance' => ['is_active' => false],
        ],
    ]);

    $reader = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId, 'user_id' => $reader->id, 'role' => 'reader',
    ]);

    $supervisor = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId, 'user_id' => $supervisor->id, 'role' => 'supervisor',
    ]);

    app()->instance('current.team', Team::with('pulse')->find($teamId));

    $this->actingAs($reader);
    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertDontSee('Fibre Bales Inventory')
        ->assertDontSee('Pith Blocks Inventory');

    $this->actingAs($supervisor);
    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertSee('Fibre Bales Inventory')
        ->assertSee('Pith Blocks Inventory')
        ->assertDontSee('You don’t have access');
});

it('hides bales and blocks cards when create or edit is revoked from supervisor', function () {
    $teamId = TeamFactory::create('Reports Hub Partial Bales '.uniqid(), true, [
        'modules' => [
            'pith' => ['is_active' => true, 'submodules' => ['bales' => true, 'blocks' => true]],
            'husks_stock' => ['is_active' => false],
            'attendance' => ['is_active' => false],
        ],
    ]);

    $supervisor = User::factory()->create([
        'is_superadmin' => 0,
        'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert([
        'team_id' => $teamId, 'user_id' => $supervisor->id, 'role' => 'supervisor',
    ]);

    app()->instance('current.team', Team::with('pulse')->find($teamId));
    $this->actingAs($supervisor);

    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertSee('Fibre Bales Inventory')
        ->assertSee('Pith Blocks Inventory');

    RbacFixtures::revoke($teamId, 'supervisor', [
        'fibre_bales:edit',
        'pith_blocks:create',
    ]);

    Livewire::test(ReportsHub::class)
        ->assertOk()
        ->assertDontSee('Fibre Bales Inventory')
        ->assertDontSee('Pith Blocks Inventory');
});
