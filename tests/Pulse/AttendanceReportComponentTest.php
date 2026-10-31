<?php

use App\Livewire\Pulse\Reports\Attendance\AttendanceReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Attendance Report Component Test '.uniqid());
    $this->user = User::factory()->create([
        'is_superadmin' => 1,
        'current_team_id' => $this->teamId,
    ]);
    $this->actingAs($this->user);

    $this->staffId = DB::table('staffs')->insertGetId([
        'team_id' => $this->teamId,
        'staff_name' => 'S. Kumar',
        'salary_per_hr' => 110.00,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('renders attendance report component with staff list', function () {
    Livewire::test(AttendanceReport::class)
        ->assertOk()
        ->assertSee('S. Kumar')
        ->assertSet('selectedWorkers', ['all']);
});

it('updates date range and persists it to the global report session', function () {
    session([
        "global_report_start_date_{$this->teamId}" => '2026-08-10',
        "global_report_end_date_{$this->teamId}" => '2026-08-10',
        "global_report_preset_{$this->teamId}" => 'Yesterday',
    ]);

    Livewire::test(AttendanceReport::class)
        ->assertSet('startDate', '2026-08-10')
        ->assertSet('presetLabel', 'Yesterday')
        ->dispatch('date-range-updated', startDate: '2026-08-01', endDate: '2026-08-07', preset: 'This Week')
        ->assertSet('startDate', '2026-08-01')
        ->assertSet('endDate', '2026-08-07')
        ->assertSet('presetLabel', 'This Week');

    expect(session("global_report_start_date_{$this->teamId}"))->toBe('2026-08-01')
        ->and(session("global_report_end_date_{$this->teamId}"))->toBe('2026-08-07')
        ->and(session("global_report_preset_{$this->teamId}"))->toBe('This Week');
});

it('toggles worker selection from all to empty without coercing to all workers', function () {
    Livewire::test(AttendanceReport::class)
        ->call('selectAllWorkers')
        ->assertSet('selectedWorkers', ['all'])
        ->call('unselectAllWorkers')
        ->assertSet('selectedWorkers', [])
        ->assertSee('Select staff to run the attendance report');
});

it('streams pdf export for attendance report', function () {
    Livewire::test(AttendanceReport::class)
        ->call('exportPdf')
        ->assertOk();
});
