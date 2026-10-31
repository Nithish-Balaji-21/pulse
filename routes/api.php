<?php

use App\Http\Controllers\CustomCurrentTeamController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\Pulse\Api\ApiController;
use App\Http\Controllers\Pulse\Api\ExpensesApiController;
use App\Http\Controllers\Pulse\Api\GeofenceDeviceLogController;
use App\Http\Controllers\Pulse\Api\GeofenceEventController;
use App\Http\Controllers\Pulse\Api\GeofenceLocationController;
use App\Http\Controllers\Pulse\Api\GrowBags\GrowbagProductionApiController;
use App\Http\Controllers\Pulse\Api\MiniBale\MiniBaleProductionListController;
use App\Http\Controllers\Pulse\Api\MiniBale\MiniBaleProductionSaveController;
use App\Http\Controllers\Pulse\Api\NotificationPreferencesController;
use App\Http\Controllers\Pulse\Api\Pith\PithBlocksProductionListController;
use App\Http\Controllers\Pulse\Api\Pith\PithBlocksProductionSaveController;
use App\Http\Controllers\Pulse\Api\PithProductionController;
use App\Http\Controllers\Pulse\Api\PithProductionInventoryController;
use App\Http\Controllers\Pulse\AssetController;
use App\Http\Controllers\Pulse\AttendanceController;
use App\Http\Controllers\Pulse\Dashboard\FibreModule\DashboardFibreApiController;
use App\Http\Controllers\Pulse\Dashboard\GrowBags\DashboardGrowBagsApiController;
use App\Http\Controllers\Pulse\Dashboard\PithLoadModule\DashboardPithLoadApiController;
use App\Http\Controllers\Pulse\Dashboard\PithLoadModule\DashboardPithLoadController;
use App\Http\Controllers\Pulse\DeviceController;
use App\Http\Controllers\Pulse\HusksDailyStockController;
use App\Http\Controllers\Pulse\IoT\IotFibreApiController;
use App\Http\Controllers\Pulse\IoT\IoTGrowbagsApiController;
use App\Http\Controllers\Pulse\IoT\IotPithApiController;
use App\Http\Controllers\Pulse\IoT\IotPithController;
use App\Http\Controllers\Pulse\IoT\IotPithDateController;
use App\Http\Controllers\Pulse\PithInventoryController;
use App\Http\Controllers\Pulse\PithLocationGroupsController;
use App\Http\Controllers\Pulse\PithLocationLoadsController;
use App\Http\Controllers\Pulse\PithLocationsController;
use App\Http\Controllers\Pulse\PithTransferController;
use App\Http\Controllers\Pulse\PithWateringController;
use App\Http\Controllers\Pulse\ShiftsController;
use App\Http\Controllers\Pulse\TeamModuleController;
use App\Http\Controllers\Pulse\WeatherController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffsController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\VerifyCurrentTeam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::post('login', [ApiController::class, 'login']);
Route::post('/send-otp', [ApiController::class, 'sendOTP']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [ApiController::class, 'logout']);
});

Route::middleware(['auth:sanctum', VerifyCurrentTeam::class])->group(function () {
    Route::get('/validate-token', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'valid' => true,
            'user' => $user,
            'permissions' => $user->currentTeamPermissions(),
        ]);
    });
    Route::post('/store-device-token', [ApiController::class, 'refreshFcm']);

    Route::prefix('teams/{team}')->group(function () {
        Route::get('/geofence-locations/assigned', [GeofenceEventController::class, 'assigned']);
        Route::middleware('can:geofence_locations.view')->group(function () {
            Route::get('/geofence-locations', [GeofenceLocationController::class, 'index']);
            Route::get('/geofence-locations/{location}', [GeofenceLocationController::class, 'show']);
            Route::get('/staff/{staff}/geofence-events', [GeofenceEventController::class, 'staffEvents']);
            Route::get('/geofence-presence', [GeofenceEventController::class, 'presence']);
            Route::get('/staff-locations/staff', [GeofenceEventController::class, 'staff']);
        });
        Route::middleware('can:geofence_locations.create')->group(function () {
            Route::post('/geofence-locations', [GeofenceLocationController::class, 'store']);
        });
        Route::middleware('can:geofence_locations.edit')->group(function () {
            Route::put('/geofence-locations/{location}', [GeofenceLocationController::class, 'update']);
            Route::post('/geofence-locations/{location}/deactivate', [GeofenceLocationController::class, 'deactivate']);
        });
        Route::middleware('can:geofence_locations.delete')->group(function () {
            Route::delete('/geofence-locations/{location}', [GeofenceLocationController::class, 'destroy']);
        });
        Route::post('/geofence-events', [GeofenceEventController::class, 'store']);
        Route::post('/geofence-logs', [GeofenceDeviceLogController::class, 'store']);
        Route::middleware('can:geofence_locations.view')->group(function () {
            Route::get('/geofence-logs', [GeofenceDeviceLogController::class, 'index']);
        });
    });

    Route::get('/husks-daily-stock', [HusksDailyStockController::class, 'index']);
    Route::get('/husks-daily-stock/summary', [HusksDailyStockController::class, 'summary']);
    Route::get('/husks-daily-stock/trend', [HusksDailyStockController::class, 'trend']);
    Route::get('/husks-daily-stock/status', [HusksDailyStockController::class, 'status']);
    Route::get('/husks-daily-stock/show-daily-checklist', [HusksDailyStockController::class, 'showDailyChecklist']);
    Route::get('/husks-daily-stock/fetch-latest', [HusksDailyStockController::class, 'latest']);

    Route::get('/dashboard/pith', [DashboardPithLoadController::class, 'index']);

    Route::get('/dashboard/fibre/summary/{format}', [DashboardFibreApiController::class, 'fetchSummaryData']);
    Route::get('/dashboard/fibre/detailed', [DashboardFibreApiController::class, 'fetchDetailedData']);

    Route::get('/dashboard/growbags/summary/{format}', [DashboardGrowBagsApiController::class, 'fetchSummaryData']);
    Route::get('/dashboard/growbags/detailed', [DashboardGrowBagsApiController::class, 'fetchDetailedData']);

    Route::get('/dashboard/pith/summary/{format}', [DashboardPithLoadApiController::class, 'fetchSummaryData']);
    Route::get('/dashboard/pith/detailed', [DashboardPithLoadApiController::class, 'fetchDetailedData']);

    Route::get('/iot-pith-date/{date}/fetch-events', [IotPithDateController::class, 'fetchDatewiseData']);
    Route::get('/iot-pith-weekly/fetch-summary', [IotPithController::class, 'fetchSummaryByDate']);

    Route::get('/iot-pith/insights', [IotPithApiController::class, 'index']);

    Route::get('/iot-fibre/insights', [IotFibreApiController::class, 'index']);

    Route::get('/iot-growbags/insights', [IoTGrowbagsApiController::class, 'index']);

    Route::middleware('can:view-pith-production')->group(function () {
        Route::get('/pith', [PithProductionController::class, 'index']);
        Route::get('/pith/report/{id}', [PithProductionController::class, 'generateReport']);
        // Production-assignment modal data for a specific shift record.
        Route::get('/pith/inventory/{id}', [PithProductionInventoryController::class, 'getInventory']);
    });

    Route::get('/pith/shifts', [PithProductionController::class, 'getExistingProductions']);
    Route::get('/pith/eb', [PithProductionController::class, 'getEb']);

    Route::middleware('can:view-pith-inventory')->group(function () {
        Route::get('/pith/inventory', [PithInventoryController::class, 'index']);
        Route::get('/pith/location/{locationId}', [PithLocationLoadsController::class, 'index']);
        Route::get('/pith/stockDepletionHistory/{locationId}', [PithLocationLoadsController::class, 'stockDepletionHistory']);
    });
    Route::post('/pith/closelocationstock/{location_id}', [PithProductionInventoryController::class, 'closeLocationStock'])
        ->middleware(['can:view-pith-inventory', 'can:close_location_stock.close']);

    Route::get('/pith-blocks', [PithBlocksProductionListController::class, 'index'])
        ->middleware('can:pith_blocks.view');

    Route::get('/growbag-production', [GrowbagProductionApiController::class, 'index'])
        ->middleware('can:growbag_production.view');

    Route::get('/fibre', [MiniBaleProductionListController::class, 'index'])
        ->middleware('can:fibre_bales.view');

    Route::get('/weather/hourly', [WeatherController::class, 'fetchHourlyWeatherEvents']);
    Route::get('/weather/daily', [WeatherController::class, 'fetchDailyWeatherEvents']);
    Route::get('/weather/map', [WeatherController::class, 'map'])->name('api.weather.map');

    Route::get('/attendance', [AttendanceController::class, 'getStaffAttendanceSummary'])
        ->middleware('can:attendance.view');
    Route::get('/attendance/details', [AttendanceController::class, 'getStaffAttendanceDetails'])
        ->middleware('can:attendance.view');

    Route::post('/current-team/{teamId}', [CustomCurrentTeamController::class, 'switchTeam']);

    // Asset details (used by mobile app)
    Route::get('/assets/view-details/{id}', [AssetController::class, 'viewDetails']);

    Route::get('/assets', [AssetController::class, 'index'])
        ->middleware('can:asset_tracking.view');

    Route::get('/attendance/exists', [AttendanceController::class, 'hasRecords']);

    // Alam Cocos Expenses (read-only). Same access as the web: any member of a
    // factory whose Expenses connection is live — no separate permission.
    Route::get('/expenses/status', [ExpensesApiController::class, 'status']);
    Route::get('/expenses/summary', [ExpensesApiController::class, 'summary']);
    Route::get('/expenses/report', [ExpensesApiController::class, 'report']);
    Route::get('/expenses/report/pdf', [ExpensesApiController::class, 'pdf']);

    Route::get('team/modules', [TeamModuleController::class, 'getStatus']);

    Route::get('/notifications', [NotificationsController::class, 'index']);
    Route::post('/notifications/mark-as-read/{ids}', [NotificationsController::class, 'markAsRead']);
    Route::post('/notifications/{id}/mark-individual-read', [NotificationsController::class, 'markAsReadIndividual']);

    // Personal notification settings — available to every team member
    Route::get('/notification-preferences', [NotificationPreferencesController::class, 'index']);
    Route::get('/notification-preferences/types', [NotificationPreferencesController::class, 'types']);
    Route::put('/notification-preferences', [NotificationPreferencesController::class, 'update']);
    Route::get('/notification-preferences/factories', [NotificationPreferencesController::class, 'factories']);
    Route::put('/notification-preferences/factories/{teamId}', [NotificationPreferencesController::class, 'updateFactory']);

    // Pith production add/edit
    Route::middleware('can:pith_production.create')->group(function () {
        Route::get('/pith/add', [PithProductionController::class, 'add']);
        Route::post('/pith/store', [PithProductionController::class, 'store']);
    });
    Route::middleware('can:pith_production.edit')->group(function () {
        Route::get('/pith/edit/{id}', [PithProductionController::class, 'edit']);
        Route::put('/pith/update/{id}', [PithProductionController::class, 'update']);

        // Assigning offloaded pith to inventory locations is part of editing a
        // production record, not a separate inventory-outward capability.
        Route::post('/pith/inventory/store', [PithProductionInventoryController::class, 'updateLoads']);
    });

    // Pith taken (outward) add/edit — parent inventory view required alongside child caps
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_outward.create'])->group(function () {
        Route::get('/pith/taken/add', [PithLocationLoadsController::class, 'add']);
        Route::post('/pith/taken/store', [PithLocationLoadsController::class, 'store']);
    });
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_outward.edit'])->group(function () {
        Route::get('/pith/taken/edit/{id}', [PithLocationLoadsController::class, 'edit']);
        Route::put('/pith/taken/update/{id}', [PithLocationLoadsController::class, 'update']);
    });

    // Assets: create/purchase/outward/return
    Route::middleware('can:asset_tracking.create')->group(function () {
        Route::get('/assets/add', [AssetController::class, 'add']);
        Route::post('/assets/store', [AssetController::class, 'store']);
        Route::post('/assets/purchase/store', [AssetController::class, 'purchaseStore']);
        Route::post('/assets/outward/store', [AssetController::class, 'outwardStore']);
        Route::post('/assets/return/store', [AssetController::class, 'returnStore']);
    });
    Route::middleware('can:asset_tracking.edit')->group(function () {
        Route::get('/assets/edit/{id}', [AssetController::class, 'edit']);
        Route::put('/assets/purchase/update/{id}', [AssetController::class, 'purchaseUpdate']);
        Route::put('/assets/outward/update/{id}', [AssetController::class, 'outwardUpdate']);
        Route::put('/assets/return/update/{id}', [AssetController::class, 'returnUpdate']);
    });

    // Attendance manager's staff list for a given day — same tier as editing attendance
    Route::middleware('can:attendance.edit')->group(function () {
        Route::get('/attendance/list/{date}', [AttendanceController::class, 'getAttendanceData']);
    });

    // Supervisor+: surplus loads (manual inward) — parent inventory view required alongside child caps
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_inward.create'])->group(function () {
        Route::post('/pith/surplus/store', [PithLocationLoadsController::class, 'storeSurplus']);
    });
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_inward.edit'])->group(function () {
        Route::get('/pith/surplus/edit/{id}', [PithLocationLoadsController::class, 'editSurplus']);
        Route::put('/pith/surplus/update/{id}', [PithLocationLoadsController::class, 'updateSurplus']);
    });
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_inward.delete'])->group(function () {
        Route::delete('/pith/surplus/delete/{id}', [PithLocationLoadsController::class, 'deleteSurplus']);
    });
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_outward.delete'])->group(function () {
        Route::delete('/pith/taken/delete/{id}', [PithLocationLoadsController::class, 'destroy']);
    });

    // Pith watering — parent inventory view required alongside child caps
    Route::middleware(['can:view-pith-inventory', 'can:pith_watering.create'])->group(function () {
        Route::post('/pith/watering/store', [PithWateringController::class, 'store']);
    });
    Route::middleware(['can:view-pith-inventory', 'can:pith_watering.edit'])->group(function () {
        Route::put('/pith/watering/update/{id}', [PithWateringController::class, 'update']);
    });
    Route::middleware(['can:view-pith-inventory', 'can:pith_watering.delete'])->group(function () {
        Route::delete('/pith/watering/delete/{id}', [PithWateringController::class, 'destroy']);
    });

    // Pith stock transfer — an outward at one location and an inward at another, so both caps
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_outward.create', 'can:pith_inventory_inward.create'])->group(function () {
        Route::post('/pith/transfer/store', [PithTransferController::class, 'store']);
    });
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_outward.edit', 'can:pith_inventory_inward.edit'])->group(function () {
        Route::put('/pith/transfer/update/{id}', [PithTransferController::class, 'update']);
    });
    Route::middleware(['can:view-pith-inventory', 'can:pith_inventory_outward.delete', 'can:pith_inventory_inward.delete'])->group(function () {
        Route::delete('/pith/transfer/delete/{id}', [PithTransferController::class, 'destroy']);
    });

    // Supervisor+: pith production delete
    Route::middleware('can:pith_production.delete')->group(function () {
        Route::delete('/pith/delete/{id}', [PithProductionController::class, 'destroy']);
    });

    // Supervisor+: pith-blocks CRUD
    Route::middleware('can:pith_blocks.create')->group(function () {
        Route::get('/pith-blocks/add', [PithBlocksProductionSaveController::class, 'add']);
        Route::post('/pith-blocks/store', [PithBlocksProductionSaveController::class, 'store']);
    });
    Route::middleware('can:pith_blocks.edit')->group(function () {
        Route::get('/pith-blocks/edit/{id}', [PithBlocksProductionSaveController::class, 'edit']);
        Route::put('/pith-blocks/update/{id}', [PithBlocksProductionSaveController::class, 'update']);
    });
    Route::middleware('can:pith_blocks.delete')->group(function () {
        Route::delete('/pith-blocks/delete/{id}', [PithBlocksProductionListController::class, 'destroy']);
    });

    // Supervisor+: fibre bales CRUD
    Route::middleware('can:fibre_bales.create')->group(function () {
        Route::get('/fibre/add', [MiniBaleProductionSaveController::class, 'add']);
        Route::post('/fibre/store', [MiniBaleProductionSaveController::class, 'store']);
    });
    Route::middleware('can:fibre_bales.edit')->group(function () {
        Route::get('/fibre/edit/{id}', [MiniBaleProductionSaveController::class, 'edit']);
        Route::put('/fibre/update/{id}', [MiniBaleProductionSaveController::class, 'update']);
    });
    Route::middleware('can:fibre_bales.delete')->group(function () {
        Route::delete('/fibre/delete/{id}', [MiniBaleProductionListController::class, 'destroy']);
    });

    // Owner/Admin: factory customization mutations
    Route::middleware('can:factory_customizations.edit')->group(function () {
        Route::get('/settings/edit/{id}', [SettingsController::class, 'edit']);
        Route::put('/settings/update/{id}', [SettingsController::class, 'update']);
    });

    Route::middleware('can:shift_settings.edit')->group(function () {
        Route::put('/shifts', [ShiftsController::class, 'saveSchedule']);
        Route::put('/shifts/anchor', [ShiftsController::class, 'updateAnchor']);
        Route::get('/shifts/edit/{id}', [ShiftsController::class, 'edit']);
        Route::put('/shifts/update/{id}', [ShiftsController::class, 'update']);
        Route::post('/shifts/toggle/{id}', [ShiftsController::class, 'toggleActive']);
    });
    Route::middleware('can:shift_settings.create')->group(function () {
        Route::post('/shifts/store', [ShiftsController::class, 'store']);
    });
    Route::middleware('can:shift_settings.delete')->group(function () {
        Route::delete('/shifts/delete/{id}', [ShiftsController::class, 'destroy']);
    });

    Route::middleware('can:view-superadmin-settings')->group(function () {
        Route::post('/devices/store', [DeviceController::class, 'store']);
        Route::get('/devices/edit/{id}', [DeviceController::class, 'edit']);
        Route::put('/devices/update/{id}', [DeviceController::class, 'update']);
        Route::post('/devices/toggle/{id}', [DeviceController::class, 'toggleActivity']);
        Route::delete('/devices/delete/{id}', [DeviceController::class, 'destroy']);
    });

    // Admin+: pith locations/groups
    Route::middleware('can:pith_locations.create')->group(function () {
        Route::post('/pithlocations/store', [PithLocationsController::class, 'store']);
    });
    Route::middleware('can:pith_locations.edit')->group(function () {
        Route::get('/pithlocations/edit/{id}', [PithLocationsController::class, 'edit']);
        Route::put('/pithlocations/update/{id}', [PithLocationsController::class, 'update']);
        Route::post('/pithlocations/toggle/{id}', [PithLocationsController::class, 'toggleActive']);
    });
    Route::middleware('can:pith_locations.delete')->group(function () {
        Route::delete('/pithlocations/delete/{id}', [PithLocationsController::class, 'destroy']);
    });

    Route::middleware('can:pith_location_groups.create')->group(function () {
        Route::post('/pithlocationgroups/store', [PithLocationGroupsController::class, 'store']);
    });
    Route::middleware('can:pith_location_groups.edit')->group(function () {
        Route::get('/pithlocationgroups/edit/{id}', [PithLocationGroupsController::class, 'edit']);
        Route::put('/pithlocationgroups/update/{id}', [PithLocationGroupsController::class, 'update']);
        Route::post('/pithlocationgroups/toggle/{id}', [PithLocationGroupsController::class, 'toggleActive']);
    });
    Route::middleware('can:pith_location_groups.delete')->group(function () {
        Route::delete('/pithlocationgroups/delete/{id}', [PithLocationGroupsController::class, 'destroy']);
    });

    // Admin+: staff workers
    Route::middleware('can:manage_staff.create')->group(function () {
        Route::post('/staffs/store', [StaffsController::class, 'store']);
    });
    Route::middleware('can:manage_staff.edit')->group(function () {
        Route::get('/staffs/edit/{id}', [StaffsController::class, 'edit']);
        Route::put('/staffs/update/{id}', [StaffsController::class, 'update']);
        Route::post('/staffs/toggle/{id}', [StaffsController::class, 'toggleActivity']);
    });
    Route::middleware('can:manage_staff.delete')->group(function () {
        Route::delete('/staffs/delete/{id}', [StaffsController::class, 'destroy']);
    });

    // Attendance add/edit — supervisor and above
    Route::middleware('can:attendance.edit')->group(function () {
        Route::post('/attendance/bulkstore/{date}', [AttendanceController::class, 'saveAttendance']);
        Route::get('/attendance/add', [AttendanceController::class, 'add']);
        Route::get('/attendance/edit/{id}', [AttendanceController::class, 'edit']);
        Route::post('/attendance/store', [AttendanceController::class, 'store']);
    });

    // Husk stock update — supervisor+
    Route::post('/husks-daily-stock/update', [HusksDailyStockController::class, 'update'])
        ->middleware('can:husk_inventory.update');

    // Admin-tier deletes (assets, attendance)
    Route::middleware('can:asset_tracking.delete')->group(function () {
        Route::delete('/assets/delete/{id}', [AssetController::class, 'destroy']);
        Route::delete('/assets/purchase/delete/{id}', [AssetController::class, 'purchaseDelete']);
        Route::delete('/assets/outward/delete/{id}', [AssetController::class, 'outwardDelete']);
        Route::delete('/assets/return/delete/{id}', [AssetController::class, 'returnDelete']);
    });
    // No attendance:delete capability in the matrix — deleting an attendance record is
    // folded into the edit capability, matching AttendanceController::destroy().
    Route::middleware('can:attendance.edit')->group(function () {
        Route::delete('/attendance/delete/{id}', [AttendanceController::class, 'destroy']);
    });

    // Factory Setup GETs — per-module view (same as web)
    Route::get('/settings', [SettingsController::class, 'index'])
        ->middleware('can:factory_customizations.view');
    Route::get('/shifts', [ShiftsController::class, 'index'])
        ->middleware('can:shift_settings.view');

    Route::middleware(['can:view-pith-locations'])->group(function () {
        Route::get('/pithlocations', [PithLocationsController::class, 'index']);
        Route::get('/pithlocationgroups', [PithLocationGroupsController::class, 'index']);
    });

    Route::middleware(['can:view-superadmin-settings'])->group(function () {
        Route::get('/devices', [DeviceController::class, 'index']);
    });

    Route::middleware(['can:view-staff'])->group(function () {
        Route::get('/staffs', [StaffsController::class, 'index']);
    });

    Route::middleware(['can:view-users'])->group(function () {
        Route::get('/users', [UserController::class, 'index']);
    });

    Route::middleware(['can:attendance.salary_report'])->group(function () {
        Route::get('/attendance/report', [AttendanceController::class, 'getAttendanceReport']);
        Route::get('/attendance/report/summary', [AttendanceController::class, 'getAttendanceReportSummary']);
        Route::get('/attendance/report/pdf', [AttendanceController::class, 'exportAttendanceReportPdf']);
    });

    Route::get('/get-image', function (Request $request) {
        $filename = $request->query('filename');
        if (! $filename || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return response()->json(['error' => 'Filename is required'], 400);
        }

        $disk = Storage::disk('public');
        $relative = 'attachments/'.$filename;
        if (! $disk->exists($relative)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        return Response::file($disk->path($relative), [
            'Content-Type' => $disk->mimeType($relative) ?: 'application/octet-stream',
        ]);
    });
});
