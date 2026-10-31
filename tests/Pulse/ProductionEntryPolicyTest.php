<?php

use App\Models\Pulse\PithProduction;
use App\Models\Settings;
use App\Services\Pulse\ProductionEntryPolicy;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

function entryPolicy(

    int $days = 10,

    string $live = '2026-08-17',

    string $key = ProductionEntryPolicyKeys::BLOCKS,

): ProductionEntryPolicy {

    return new ProductionEntryPolicy(1, $key, $days, $live);

}

it('honours window days from customizations', function () {

    $policy = entryPolicy(24);

    expect($policy->windowDays())->toBe(24);

});

it('blocks create on the live shift day and allows up to the day before', function () {

    $policy = entryPolicy();

    expect($policy->canCreate($policy->liveShiftDay()))->toBeFalse()

        ->and($policy->canCreate($policy->maxCreateShiftDate()))->toBeTrue();

});

it('blocks edit and delete outside the window', function () {

    $policy = entryPolicy(5, '2026-08-17');

    expect($policy->canEdit('2026-08-07'))->toBeFalse()

        ->and($policy->canEdit($policy->minShiftDate()))->toBeTrue()

        ->and($policy->canEdit($policy->maxCreateShiftDate()))->toBeTrue()

        ->and($policy->canDelete('2026-08-07'))->toBeFalse();

});

it('reads pulse_settings for the edit window', function () {

    $teamId = TeamFactory::create('Entry Policy Test');

    Settings::query()->updateOrCreate(

        ['team_id' => $teamId, 'key' => ProductionEntryPolicyKeys::LOADS],

        [

            'module' => 'pith',

            'text' => ProductionEntryPolicyKeys::DEFINITIONS[ProductionEntryPolicyKeys::LOADS]['text'],

            'value' => '5',

            'options' => ProductionEntryPolicyKeys::dayOptions(),

            'value_remark' => null,

        ]

    );

    ProductionEntryPolicy::forgetCacheForTeam($teamId);

    $policy = PithProduction::entryPolicyFor($teamId);

    $oldDate = Carbon::parse($policy->liveShiftDay())->subDays(10)->toDateString();

    $recentDate = $policy->maxCreateShiftDate();

    $shiftId = DB::table('pulse_shifts')->insertGetId([

        'team_id' => $teamId,

        'name' => 'Shift 1',

        'start_time' => '08:00:00',

        'end_time' => '18:00:00',

        'is_active' => 1,

        'created_at' => now(),

        'updated_at' => now(),

    ]);

    $staffId = DB::table('staffs')->insertGetId([

        'team_id' => $teamId,

        'staff_name' => 'R. Selvam',

        'created_at' => now(),

        'updated_at' => now(),

    ]);

    $old = PithProduction::create([

        'team_id' => $teamId,

        'shift_date' => $oldDate,

        'shift_id' => $shiftId,

        'shift_name' => 'Shift 1',

        'staff_id' => $staffId,

        'start_time' => $oldDate.' 08:00:00',

        'end_time' => $oldDate.' 16:00:00',

        'cft_produced' => 100,

    ]);

    $recent = PithProduction::create([

        'team_id' => $teamId,

        'shift_date' => $recentDate,

        'shift_id' => $shiftId,

        'shift_name' => 'Shift 1',

        'staff_id' => $staffId,

        'start_time' => $recentDate.' 08:00:00',

        'end_time' => $recentDate.' 16:00:00',

        'cft_produced' => 100,

    ]);

    expect($policy->windowDays())->toBe(5)

        ->and($old->canEditRecord())->toBeFalse()

        ->and($recent->canEditRecord())->toBeTrue();

});
