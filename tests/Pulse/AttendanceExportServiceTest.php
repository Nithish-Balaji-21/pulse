<?php

use App\Services\Pulse\AttendanceExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

beforeEach(function () {
    $this->teamId = TeamFactory::create('Attendance Export Test '.uniqid());
    $this->service = new AttendanceExportService;

    $this->staff1Id = DB::table('staffs')->insertGetId([
        'team_id' => $this->teamId,
        'staff_name' => 'K. Raman',
        'salary_per_hr' => 100.00,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->staff2Id = DB::table('staffs')->insertGetId([
        'team_id' => $this->teamId,
        'staff_name' => 'M. Velu',
        'salary_per_hr' => 120.00,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('formats decimal hours into human readable string', function () {
    expect($this->service->formatHours(8.5))->toBe('8h 30m')
        ->and($this->service->formatHours(8.0))->toBe('8h')
        ->and($this->service->formatHours(0.5))->toBe('30m')
        ->and($this->service->formatHours(0.0))->toBe('0h')
        ->and($this->service->formatHours(-1.0))->toBe('0h');
});

it('builds attendance report with Present or Absent only', function () {
    DB::table('pulse_attendance')->insert([
        'team_id' => $this->teamId,
        'staff_id' => $this->staff1Id,
        'date' => '2026-08-10',
        'duration' => 8.0,
        'salary' => 800.00,
        'task_description' => 'Sieving and packing',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Any duration > 0 counts as Present (no half-day)
    DB::table('pulse_attendance')->insert([
        'team_id' => $this->teamId,
        'staff_id' => $this->staff2Id,
        'date' => '2026-08-10',
        'duration' => 4.0,
        'salary' => 480.00,
        'task_description' => 'Baler maintenance',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = $this->service->buildReport(
        $this->teamId,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-10')
    );

    expect($report['module'])->toBe('attendance')
        ->and($report['summary']['total_workers'])->toBe(2)
        ->and($report['summary']['total_present_days'])->toBe(2)
        ->and($report['summary']['total_absent_days'])->toBe(0)
        ->and($report['summary'])->not->toHaveKey('total_half_days')
        ->and($report['summary']['total_salary'])->toBe(1280.0)
        ->and($report['summary']['total_hours_decimal'])->toBe(12.0)
        ->and($report['summary']['total_hours_formatted'])->toBe('12h');

    $row1 = collect($report['worker_summary'])->firstWhere('staff_id', $this->staff1Id);
    $row2 = collect($report['worker_summary'])->firstWhere('staff_id', $this->staff2Id);

    expect($row1['present_days'])->toBe(1)
        ->and($row1['total_salary'])->toBe(800.0)
        ->and($row2['present_days'])->toBe(1)
        ->and($row2['total_salary'])->toBe(480.0);
});

it('returns empty report when no workers are selected', function () {
    $report = $this->service->buildReport(
        $this->teamId,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-10'),
        []
    );

    expect($report['summary']['total_workers'])->toBe(0)
        ->and($report['worker_summary'])->toBe([])
        ->and($report['applied_filter_label'])->toContain('No workers selected');
});

it('filters attendance report by specific worker IDs', function () {
    DB::table('pulse_attendance')->insert([
        'team_id' => $this->teamId,
        'staff_id' => $this->staff1Id,
        'date' => '2026-08-10',
        'duration' => 8.0,
        'salary' => 800.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pulse_attendance')->insert([
        'team_id' => $this->teamId,
        'staff_id' => $this->staff2Id,
        'date' => '2026-08-10',
        'duration' => 8.0,
        'salary' => 960.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = $this->service->buildReport(
        $this->teamId,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-10'),
        [$this->staff1Id]
    );

    expect($report['summary']['total_workers'])->toBe(1)
        ->and($report['summary']['total_salary'])->toBe(800.0)
        ->and(count($report['worker_summary']))->toBe(1)
        ->and($report['worker_summary'][0]['staff_id'])->toBe($this->staff1Id);
});

it('marks missing attendance rows as Absent', function () {
    $report = $this->service->buildReport(
        $this->teamId,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-10')
    );

    expect($report['summary']['total_present_days'])->toBe(0)
        ->and($report['summary']['total_absent_days'])->toBe(2)
        ->and($report['summary']['attendance_rate'])->toBe(0.0);
});
