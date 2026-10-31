<?php

use App\Livewire\Pulse\Pith\PithInventory;
use App\Models\Pulse\PithLocation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * Renders the actual Pith Inventory page and its Filter & Export popup end-to-end (real
 * routing, real middleware, real Blade compilation) to catch anything a service-level test
 * can't: undefined Blade variables, broken @include paths, route() calls to a route that
 * doesn't exist, etc.
 */
beforeEach(function () {
    $this->travelTo('2026-09-23 10:00:00');
    $this->teamId = TeamFactory::create('Pith Export UI Factory '.uniqid());

    $this->user = User::factory()->create([
        'is_superadmin' => 0,
        'is_active' => 1,
        'current_team_id' => $this->teamId,
    ]);

    DB::table('team_user')->insert([
        'team_id' => $this->teamId, 'user_id' => $this->user->id, 'role' => 'staff',
    ]);

    app()->instance('current.team', Team::find($this->teamId));
    $this->actingAs($this->user);
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('renders the Filter & Export button, defaults the range to yesterday, and shows the export actions', function () {
    $this->get(route('pith.inventory', ['team' => $this->teamId]))
        ->assertOk()
        ->assertSee('Filter &amp; Export', false)
        ->assertSee('Inventory History');

    $component = Livewire::test(PithInventory::class)
        ->call('openExportModal')
        ->assertSet('showExportModal', true)
        ->assertSet('exportPreset', 'yesterday')
        ->assertSet('exportStartDate', '2026-09-22')
        ->assertSet('exportEndDate', '2026-09-22')
        ->assertSee('Export PDF')
        ->assertSee('Export image')
        ->assertSee('Watering column')
        ->assertSee('Empty locations');

    $expectedPdfUrl = route('pith.inventory.export.pdf', [
        'team' => $this->teamId,
        'start_date' => '2026-09-22',
        'end_date' => '2026-09-22',
        'watering' => 1,
        'empty' => 0,
    ]);

    // Blade's {{ }} escapes the "&" between query params in the rendered href.
    $component->assertSeeHtml(e($expectedPdfUrl));

    // Closing the popup clears the rendered results, so reopening starts fresh.
    $component->call('closeExportModal')
        ->assertSet('showExportModal', false)
        ->assertSet('exportReportData', null);
});

it('maps each preset to its date range', function (string $preset, string $start, string $end) {
    Livewire::test(PithInventory::class)
        ->call('openExportModal')
        ->call('setExportPreset', $preset)
        ->assertSet('exportPreset', $preset)
        ->assertSet('exportStartDate', $start)
        ->assertSet('exportEndDate', $end);
})->with([
    'yesterday' => ['yesterday', '2026-09-22', '2026-09-22'],
    'last 7 days' => ['week', '2026-09-16', '2026-09-22'],
    'this month' => ['month', '2026-09-01', '2026-09-23'],
]);

it('switches to a custom range when a date is edited, swapping reversed dates', function () {
    Livewire::test(PithInventory::class)
        ->call('openExportModal')
        ->set('exportStartDate', '2026-09-10')
        ->assertSet('exportPreset', 'custom')
        ->set('exportEndDate', '2026-09-05')
        ->assertSet('exportStartDate', '2026-09-05')
        ->assertSet('exportEndDate', '2026-09-10');
});

it('drops the watering column from the preview when it is switched off', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Preview Yard', 'is_active' => 1]);
    DB::table('pulse_pith_inventory')->insert(['pith_location_id' => $location->id, 'date' => '2026-09-22', 'cft_offloaded' => 40, 'is_archived' => 0]);

    Livewire::test(PithInventory::class)
        ->call('openExportModal')
        ->assertSee('Watered (L)')
        ->call('toggleExportWatering')
        ->assertDontSee('Watered (L)');
});

it('opens the popup on a day passed from the history page', function () {
    $this->get(route('pith.inventory', ['team' => $this->teamId, 'export' => '2026-09-12']))->assertOk();

    Livewire::withQueryParams(['export' => '2026-09-12'])
        ->test(PithInventory::class)
        ->assertSet('showExportModal', true)
        ->assertSet('exportPreset', 'custom')
        ->assertSet('exportStartDate', '2026-09-12')
        ->assertSet('exportEndDate', '2026-09-12');
});

it('exports the PDF with the watering column', function () {
    $location = PithLocation::create(['team_id' => $this->teamId, 'name' => 'Pdf Yard', 'is_active' => 1]);
    DB::table('pulse_pith_inventory')->insert(['pith_location_id' => $location->id, 'date' => '2026-09-22', 'cft_offloaded' => 40, 'is_archived' => 0]);

    $this->get(route('pith.inventory.export.pdf', ['team' => $this->teamId, 'start_date' => '2026-09-22', 'end_date' => '2026-09-22', 'watering' => 1]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
