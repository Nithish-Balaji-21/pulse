<?php

use App\Http\Controllers\CustomCurrentTeamController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\ManualCronController;
use App\Http\Controllers\Pulse\AttendanceController;
use App\Http\Controllers\Pulse\Dashboard\DashboardController;
use App\Http\Controllers\Pulse\Dashboard\FibreModule\DashboardFibreController;
use App\Http\Controllers\Pulse\Dashboard\GrowBags\DashboardGrowBagsController;
use App\Http\Controllers\Pulse\Dashboard\PithLoadModule\DashboardPithLoadController;
use App\Http\Controllers\Pulse\ExpensesController;
use App\Http\Controllers\Pulse\GeofenceSearchController;
use App\Http\Controllers\Pulse\IoT\IotFibreController;
use App\Http\Controllers\Pulse\IoT\IotFibreWeightController;
use App\Http\Controllers\Pulse\IoT\IotGrowbagsController;
use App\Http\Controllers\Pulse\IoT\IotMiniBalesController;
use App\Http\Controllers\Pulse\IoT\IotPithBlocksController;
use App\Http\Controllers\Pulse\IoT\IotPithController;
use App\Http\Controllers\Pulse\IoT\IotWaterConsumptionController;
use App\Http\Controllers\Pulse\PithInventoryExportController;
use App\Http\Controllers\Pulse\WeatherController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\AuthorizeQueueMonitor;
use App\Http\Middleware\EnsureTeamIsActive;
use App\Livewire\ManageRolePermissions;
use App\Livewire\ManageSettings;
use App\Livewire\ManageStaff;
use App\Livewire\ManageUsers;
use App\Livewire\NotificationPreferences;
use App\Livewire\Pulse\Assets\AssetList;
use App\Livewire\Pulse\Assets\AssetOutwardCreateAndEdit;
use App\Livewire\Pulse\Assets\AssetPurchaseCreateAndEdit;
use App\Livewire\Pulse\Assets\AssetReturnedCreateAndEdit;
use App\Livewire\Pulse\Assets\AssetView;
use App\Livewire\Pulse\Attendance\AttendanceManager;
use App\Livewire\Pulse\GrowBags\GrowBagProductionList;
use App\Livewire\Pulse\GrowBags\ManageGrowbagMachines;
use App\Livewire\Pulse\ManageAssetCategories;
use App\Livewire\Pulse\ManageDevices;
use App\Livewire\Pulse\ManageGeofences;
use App\Livewire\Pulse\ManagePithLocationGroups;
use App\Livewire\Pulse\ManagePithLocations;
use App\Livewire\Pulse\ManageShifts;
use App\Livewire\Pulse\ManageWaterMeters;
use App\Livewire\Pulse\ManageWaterTanks;
use App\Livewire\Pulse\MiniBale\ManageMiniBaleMachines;
use App\Livewire\Pulse\MiniBale\MiniBaleCreateAndEdit;
use App\Livewire\Pulse\MiniBale\MiniBaleProductionList;
use App\Livewire\Pulse\Pith\HuskInventory;
use App\Livewire\Pulse\Pith\PithCreateAndEdit;
use App\Livewire\Pulse\Pith\PithHistory;
use App\Livewire\Pulse\Pith\PithInventory;
use App\Livewire\Pulse\Pith\PithProductionList;
use App\Livewire\Pulse\Pith\PithReport;
use App\Livewire\Pulse\PithBlocks\PithBlocksCreateAndEdit;
use App\Livewire\Pulse\PithBlocks\PithBlocksProductionList;
use App\Livewire\Pulse\Reports\Attendance\AttendanceReport;
use App\Livewire\Pulse\Reports\Inventory\BalesInventoryReport;
use App\Livewire\Pulse\Reports\Inventory\BlocksInventoryReport;
use App\Livewire\Pulse\Reports\Inventory\HuskInventoryReport;
use App\Livewire\Pulse\Reports\Inventory\PithInventoryReport;
use App\Livewire\Pulse\Reports\ReportsHub;
use App\Livewire\Pulse\SuperAdminSettings;
use App\Livewire\SuperadminFactories;
use App\Livewire\SuperadminImpersonationLogs;
use App\Livewire\SuperadminUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Login page
Route::get('/monitor/guide/images/{image}', function (string $image) {
    abort_unless(in_array($image, ['horizon', 'nightwatch'], true), 404);

    return response()->file(resource_path('images/monitoring-guide/'.$image.'.png'));
})->middleware(['auth', AuthorizeQueueMonitor::class]);

Route::get('/', function () {
    if (Auth::check()) {
        return redirect('/dashboard');
    }

    return redirect()->route('login');
});

Route::get('/login', function () {
    if (Auth::check()) {
        return redirect('/dashboard');
    }

    return view('auth.login');
})->name('login');

Route::post('/send-otp', [UserController::class, 'sendOtp']);
Route::post('/verify-otp', [UserController::class, 'verifyOtp']);

// Authenticated pages
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {

    // Post-login landing: redirect to last used team (cookie) or default team
    Route::get('/dashboard', function (Request $request) {
        $teams = $request->user()->allTeams();

        // Nothing to redirect to, and a stale cookie would only bounce off a 403.
        if ($teams->isEmpty()) {
            return response()->view('errors.no-factory', [], 403);
        }

        $teamId = $request->cookie('last_team_id') ?? $request->user()->current_team_id ?? $teams->first()->id;

        return redirect("/{$teamId}/dashboard");
    })->name('dashboard.redirect');

    Route::put('/current-team', [CustomCurrentTeamController::class, 'update'])->name('current-team.update');
    Route::get('/user/context', [UserController::class, 'getContext']);

    // Superadmin impersonation. Registered before the {team} wildcard group so
    // these literal paths can't be shadowed by a matching {team}/... route.
    // `stop` and `write-mode` are ungated on purpose: mid-session the authenticated
    // user is the target, who would fail the superadmin gate and be trapped.
    Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
    Route::post('/impersonate/write-mode', [ImpersonationController::class, 'writeMode'])->name('impersonate.write-mode');
    Route::post('/impersonate/{user}', [ImpersonationController::class, 'start'])
        ->middleware('can:impersonate-users')
        ->name('impersonate.start');

    // All team-scoped routes
    Route::prefix('{team}')->middleware(['active.team', EnsureTeamIsActive::class])->group(function () {

        // Read-only Expenses API consumer, scoped by authenticated factory membership.
        Route::get('/expenses', [ExpensesController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/session', fn () => redirect()->route('expenses.index', ['team' => app('current.team')->id]))->name('expenses.launch');
        Route::get('/expenses/summary', [ExpensesController::class, 'summary'])->name('expenses.summary');

        // Demo Quick Wins
        Route::get('/demo-quick-wins', function () {
            return view('pulse::demo-quick-wins');
        })->name('demo-quick-wins');

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/pith', [DashboardPithLoadController::class, 'index'])->name('dashboard.pith');
        Route::get('/dashboard/fibre', [DashboardFibreController::class, 'index'])->name('dashboard.fibre');
        Route::get('/dashboard/fibre/export', [DashboardFibreController::class, 'export'])->name('fibre.export');
        Route::get('/dashboard/growbags', [DashboardGrowBagsController::class, 'index'])->name('dashboard.growbags');

        // IoT: Pith load and modules
        Route::get('/iot-pith-loads', [IotPithController::class, 'index'])->name('iot-pith-loads');
        Route::get('/iot-pith-loads/fetch-events', [IotPithController::class, 'fetchDeviceEvents']);
        Route::get('/iot-pith-loads/fetch-summary', [IotPithController::class, 'fetchDeviceStates']);

        Route::get('/iot-pith-blocks', [IotPithBlocksController::class, 'index'])->name('iot-pith-blocks');
        Route::get('/iot-pith-blocks/fetch-events', [IotPithBlocksController::class, 'fetchEventsByDate']);

        Route::get('/iot-fibre-minibales', [IotMiniBalesController::class, 'index'])->name('iot-fibre-minibales');
        Route::get('/iot-fibre-minibales/fetch-events', [IotMiniBalesController::class, 'fetchEventsByDate']);

        Route::get('/iot-water-consume', [IotWaterConsumptionController::class, 'index'])->name('iot-water-consume');
        Route::get('/iot-water-consume/fetch-events', [IotWaterConsumptionController::class, 'fetchEventsByDate']);

        Route::get('/iot-fibre-bales', [IotFibreController::class, 'index'])->name('iot-fibre-bales');
        Route::get('/iot-fibre-bales/fetch-events', [IotFibreController::class, 'fetchEventsByDate']);

        Route::get('/iot-fibre-weight', [IotFibreWeightController::class, 'index'])->name('iot-fibre-weight');
        Route::get('/iot-fibre-weight/fetch-events', [IotFibreWeightController::class, 'fetchEventsByDate']);

        // IoT: Growbags
        Route::get('/iot-growbags', [IotGrowbagsController::class, 'index'])->name('iot-growbags');
        Route::get('/iot-growbags/fetch-events', [IotGrowbagsController::class, 'fetchEventsByDate']);

        // Pith production, inventory and report
        Route::middleware('can:view-pith-production')->group(function () {
            Route::get('/pith', PithProductionList::class)->name('pith.index');
            Route::get('/pith/report/{id}', PithReport::class)->name('pith.report');
        });
        Route::middleware('can:view-pith-inventory')->group(function () {
            Route::get('/pith/inventory', PithInventory::class)->name('pith.inventory');
            Route::get('/pith/inventory/history', PithHistory::class)->name('pith.inventory.history');
            Route::get('/pith/inventory/export/pdf', [PithInventoryExportController::class, 'pdf'])->name('pith.inventory.export.pdf');
            // The location page now lives as a panel beside the inventory list.
            Route::get('/pith/taken/{locationId}', fn ($team, $locationId) => redirect()->route('pith.inventory', ['team' => $team, 'location' => $locationId]))
                ->whereNumber('locationId')
                ->name('pith.taken');
        });
        Route::middleware('can:view-husk-inventory')->group(function () {
            Route::get('/husk/inventory', HuskInventory::class)->name('husk.inventory');
        });

        // Fibre production
        Route::middleware('can:view-fibre-bales')->group(function () {
            Route::get('/fibre', MiniBaleProductionList::class)->name('fibre.index');
        });

        // Pith Blocks production
        Route::middleware('can:view-pith-blocks')->group(function () {
            Route::get('/pith-blocks', PithBlocksProductionList::class)->name('pith-blocks.index');
        });

        // Growbag production (view/filter only — no manual create/edit)
        Route::middleware('can:view-growbag-production')->group(function () {
            Route::get('/growbag-production', GrowBagProductionList::class)->name('growbag.production');
        });

        // Weather
        Route::get('/weather', [WeatherController::class, 'index'])->name('weather.index');
        Route::get('/weather/map', [WeatherController::class, 'map'])->name('weather.map');
        Route::get('/weather/fetch-events', [WeatherController::class, 'fetchWeatherEvents']);

        // Assets
        Route::middleware('can:view-assets')->group(function () {
            Route::get('/assets', AssetList::class)->name('assets.index');
            Route::get('/assets/view/{id}', AssetView::class)->name('assets.view');
        });

        // Attendance — view calendar (attendance:view); edit stays on manage-attendance
        Route::middleware('can:view-attendance')->group(function () {
            Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            Route::get('/attendance/resourcesAndAttendance', [AttendanceController::class, 'getStaffAttendanceDetails']);
        });

        // Add pith — Staff+; asset sub-record create pages
        Route::middleware('can:pith_production.create')->group(function () {
            Route::get('/pith/create', PithCreateAndEdit::class)->name('pith.create');
        });
        Route::middleware('can:pith_production.edit')->group(function () {
            Route::get('/pith/edit/{id}', PithCreateAndEdit::class)->name('pith.edit');
        });

        Route::middleware('can:asset_tracking.create')->group(function () {
            Route::get('/assets/purchase/create/{assetId}', AssetPurchaseCreateAndEdit::class)->name('assets.purchase.create');
            Route::get('/assets/outward/create/{assetId}', AssetOutwardCreateAndEdit::class)->name('assets.outward.create');
            Route::get('/assets/return/create/{outwardId}', AssetReturnedCreateAndEdit::class)->name('assets.return.create');
        });
        Route::middleware('can:asset_tracking.edit')->group(function () {
            Route::get('/assets/purchase/edit/{purchaseId}', AssetPurchaseCreateAndEdit::class)->name('assets.purchase.edit');
            Route::get('/assets/outward/edit/{outwardId}', AssetOutwardCreateAndEdit::class)->name('assets.outward.edit');
            Route::get('/assets/return/edit/{returnId}', AssetReturnedCreateAndEdit::class)->name('assets.return.edit');
        });

        // Fibre & pith-blocks — Supervisor+
        Route::middleware('can:fibre_bales.create')->group(function () {
            Route::get('/fibre/create', MiniBaleCreateAndEdit::class)->name('fibre.create');
        });
        Route::middleware('can:fibre_bales.edit')->group(function () {
            Route::get('/fibre/edit/{id}', MiniBaleCreateAndEdit::class)->name('fibre.edit');
        });

        Route::middleware('can:pith_blocks.create')->group(function () {
            Route::get('/pith-blocks/create', PithBlocksCreateAndEdit::class)->name('pith-blocks.create');
        });
        Route::middleware('can:pith_blocks.edit')->group(function () {
            Route::get('/pith-blocks/edit/{id}', PithBlocksCreateAndEdit::class)->name('pith-blocks.edit');
        });

        // Attendance editing — supervisor and above, not staff
        Route::middleware('can:attendance.edit')->group(function () {
            Route::get('/attendance/edit/{date}', AttendanceManager::class)->name('attendance.manager');
        });

        Route::get('/reports', ReportsHub::class)->name('reports.hub');
        Route::get('/reports/expenses', [ExpensesController::class, 'index'])->name('reports.expenses');

        Route::middleware('can:pith_inventory.inventory_report')->group(function () {
            Route::get('/reports/inventory/pith', PithInventoryReport::class)->name('reports.inventory.pith');
        });
        Route::middleware('can:husk_inventory.inventory_report')->group(function () {
            Route::get('/reports/inventory/husk', HuskInventoryReport::class)->name('reports.inventory.husk');
        });
        Route::middleware('can:view-bale-inventory')->group(function () {
            Route::get('/reports/inventory/bales', BalesInventoryReport::class)->name('reports.inventory.bales');
        });
        Route::middleware('can:view-block-inventory')->group(function () {
            Route::get('/reports/inventory/blocks', BlocksInventoryReport::class)->name('reports.inventory.blocks');
        });

        Route::middleware('can:attendance.salary_report')->group(function () {
            Route::get('/reports/attendance', AttendanceReport::class)->name('reports.attendance');
        });

        Route::post('/manual-cron-run/{teamId}', [ManualCronController::class, 'runYesterdayCronsForTeamById'])
            ->middleware('can:view-superadmin-settings');

        // Personal notification settings — visible to any role allowed by the matrix.
        Route::middleware(['can:view-notification-preferences'])->group(function () {
            Route::get('/notification-preferences', NotificationPreferences::class)->name('notification-preferences.index');
        });

        // Role permissions matrix
        Route::middleware(['can:view-role-matrix'])->group(function () {
            Route::get('/roles', ManageRolePermissions::class)->name('roles.index');
            Route::get('/roles/{roleKey}', ManageRolePermissions::class)->name('roles.edit');
        });

        // User management
        Route::middleware(['can:view-users'])->group(function () {
            Route::get('/users', ManageUsers::class)->name('users.index');
        });

        // Staff management
        Route::middleware(['can:view-staff'])->group(function () {
            Route::get('/staffs', ManageStaff::class)->name('staffs.index');
        });

        // Pith location operations
        Route::middleware(['can:view-pith-locations'])->group(function () {
            Route::get('/pithlocations', ManagePithLocations::class)->name('pithlocations.index');
            Route::get('/pithlocationgroups', ManagePithLocationGroups::class)->name('pithlocationgroups.index');
        });

        // Factory Setup — each page requires its own module view capability
        Route::get('/shifts', ManageShifts::class)
            ->middleware('can:shift_settings.view')
            ->name('shifts.index');
        Route::get('/geofences/search', GeofenceSearchController::class)
            ->middleware('can:geofence_locations.view')
            ->name('geofences.search');
        Route::get('/geofences', ManageGeofences::class)
            ->middleware('can:geofence_locations.view')
            ->name('geofences.index');
        Route::get('/assetcategories', ManageAssetCategories::class)
            ->middleware('can:asset_categories.view')
            ->name('assetcategories.index');
        Route::get('/settings', ManageSettings::class)
            ->middleware('can:factory_customizations.view')
            ->name('settings.index');

        Route::middleware(['can:view-superadmin-settings'])->group(function () {
            Route::get('/devices', ManageDevices::class)->name('devices.index');
        });

        // Factory Configuration — Superadmin & Owner only (excluding IoT Devices)
        Route::middleware(['can:view-factory-configuration'])->group(function () {
            Route::get('/water-meters', ManageWaterMeters::class)->name('water-meters.index');
            Route::get('/water-tanks', ManageWaterTanks::class)->name('water-tanks.index');
            Route::get('/growbag-machines', ManageGrowbagMachines::class)->name('growbag-machines.index');
            Route::get('/fibrebale-machines', ManageMiniBaleMachines::class)->name('fibrebale-machines.index');
        });

        // Superadmin-only
        Route::middleware(['can:view-superadmin-settings'])->group(function () {
            Route::get('/allusers', SuperadminUsers::class)->name('allusers.index');
            Route::get('/allfactories', SuperadminFactories::class)->name('allfactories.index');
            Route::get('/impersonation-logs', SuperadminImpersonationLogs::class)->name('impersonation-logs.index');
            Route::get('/superadmin-settings', SuperAdminSettings::class)->name('superadmin-settings.index');
            Route::view('/monitoring', 'monitoring.hub')
                ->middleware(AuthorizeQueueMonitor::class)
                ->name('superadmin.monitoring');
        });

    }); // end Route::prefix('{team}')

}); // end auth middleware
