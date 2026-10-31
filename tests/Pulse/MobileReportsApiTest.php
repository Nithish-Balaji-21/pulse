<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\RbacFixtures;
use Tests\Pulse\Support\TeamFactory;

/**
 * Mobile API behind the redesigned Reports: the Attendance/Salary report
 * summary and PDF (attendance.salary_report, same dataset as the web report),
 * and the Alam Cocos Expenses endpoints (any member of a connected factory,
 * as on the web).
 */
beforeEach(function () {
    // Expenses matches the factory by name, so no unique suffix here.
    $this->teamId = TeamFactory::create('Alam Cocos');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);
    config(['expenses.url' => 'http://localhost:8000', 'expenses.api_key' => str_repeat('k', 64)]);
    Cache::flush();
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

function fakeConnectedExpenses(): void
{
    Http::swap(new Factory);
    Http::fake([
        '*/api/pulse/alam-cocos/connection' => Http::response(['connected' => true, 'company' => 'alam_cocos']),
        '*/api/pulse/alam-cocos/expenses*' => Http::response(['connected' => true, 'company' => 'alam_cocos',
            'rows' => [['date' => '2026-10-02', 'hourly_cost' => 100, 'activity_cost' => 20, 'materials_vehicles_cost' => 30, 'total_cost' => 150]],
            'totals' => ['hourly_cost' => 100, 'activity_cost' => 20, 'materials_vehicles_cost' => 30, 'total_cost' => 150]]),
        '*/api/pulse/alam-cocos/details*' => Http::response(['connected' => true, 'company' => 'alam_cocos',
            'staff' => [
                'columns' => [
                    ['key' => 'packing', 'label' => 'Packing', 'group' => 'hourly'],
                    ['key' => 'husking', 'label' => 'Husking', 'group' => 'activity', 'unit' => 'nuts'],
                ],
                'rows' => [['name' => 'Test worker', 'cells' => [
                    'packing' => ['amount' => 100, 'hours' => 2, 'quantity' => 0],
                    'husking' => ['amount' => 20, 'hours' => 0, 'quantity' => 400],
                ]]],
                'column_totals' => [],
            ],
            'charges' => [['type' => 'vehicle', 'item' => 'Test vehicle', 'notes' => 'Trip', 'date' => '2026-10-02', 'paid_by' => 'Alam Cocos', 'billed_to' => 'Hexagro', 'amount' => 30]],
            'totals' => ['staff' => 120, 'other' => 30, 'total' => 150]]),
    ]);
}

// ── Expenses ─────────────────────────────────────────────────────────────────

it('reports the Expenses connection to every role', function () {
    fakeConnectedExpenses();
    foreach (RbacFixtures::ROLES as $role) {
        Sanctum::actingAs($this->users[$role]);
        $this->getJson('/api/expenses/status')->assertOk()->assertExactJson(['connected' => true]);
    }
});

it('returns dashboard totals for the selected period', function () {
    fakeConnectedExpenses();
    Sanctum::actingAs($this->users['reader']);
    $this->getJson('/api/expenses/summary?from=2026-10-01&to=2026-10-08')->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('totals.total_cost', 150)
        ->assertJsonPath('totals.activity_cost', 20)
        ->assertJsonPath('has_entries', true);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/expenses') && $request['from'] === '2026-10-01' && $request['to'] === '2026-10-08');
});

it('returns report rows and summary without double counting activity wages', function () {
    fakeConnectedExpenses();
    Sanctum::actingAs($this->users['reader']);
    $response = $this->getJson('/api/expenses/report?from=2026-10-01&to=2026-10-08')->assertOk()
        ->assertJsonPath('summary.staff', 120)
        ->assertJsonPath('summary.staff_count', 1)
        ->assertJsonPath('summary.activity', 20)
        ->assertJsonPath('summary.other', 30)
        ->assertJsonPath('summary.total', 150)
        ->assertJsonCount(3, 'rows');
    expect(collect($response->json('rows'))->pluck('wage_type')->all())->toBe(['hourly', 'activity', null])
        ->and($response->json('rows.1.detail'))->toBe('400 nuts')
        ->and($response->json('rows.2.billed_to'))->toBe('Hexagro');
});

it('exports the expense report as a PDF for a category', function () {
    fakeConnectedExpenses();
    Sanctum::actingAs($this->users['reader']);
    $this->get('/api/expenses/report/pdf?from=2026-10-01&to=2026-10-08&category=vehicle')
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->getJson('/api/expenses/report/pdf?from=2026-10-01&to=2026-10-08&category=invalid')->assertUnprocessable();
});

it('tells the app to hide Expenses for another factory without calling Expenses', function () {
    $other = TeamFactory::create('Hexagro Fibre');
    $owner = RbacFixtures::user($other, 'owner');
    Http::fake();
    Sanctum::actingAs($owner);
    $this->getJson('/api/expenses/status?factory_id='.$other)->assertOk()->assertExactJson(['connected' => false]);
    $this->getJson('/api/expenses/summary?from=2026-10-01&to=2026-10-08&factory_id='.$other)->assertOk()->assertExactJson(['connected' => false]);
    $this->getJson('/api/expenses/report?from=2026-10-01&to=2026-10-08&factory_id='.$other)->assertOk()->assertExactJson(['connected' => false]);
    Http::assertNothingSent();
});

it('answers 503 when connected but Expenses fails, so the app can offer Retry', function () {
    Http::swap(new Factory);
    Http::fake([
        '*/api/pulse/alam-cocos/connection' => Http::response(['connected' => true, 'company' => 'alam_cocos']),
        '*' => Http::response([], 503),
    ]);
    Sanctum::actingAs($this->users['reader']);
    $this->getJson('/api/expenses/summary?from=2026-10-01&to=2026-10-08')->assertStatus(503)->assertJsonPath('connected', true);
    $this->getJson('/api/expenses/report?from=2026-10-01&to=2026-10-08')->assertStatus(503);
});

it('rejects bad expense ranges', function () {
    Sanctum::actingAs($this->users['reader']);
    $this->getJson('/api/expenses/summary?from=2026-10-08&to=2026-10-01')->assertUnprocessable();
    $this->getJson('/api/expenses/report?from=2024-01-01&to=2026-01-01')->assertUnprocessable();
    $this->getJson('/api/expenses/report')->assertUnprocessable();
});

// ── Attendance/Salary ────────────────────────────────────────────────────────

function seedReportAttendance(int $teamId): array
{
    $akash = DB::table('staffs')->insertGetId(['team_id' => $teamId, 'staff_name' => 'Akash', 'salary_per_hr' => 100, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $banu = DB::table('staffs')->insertGetId(['team_id' => $teamId, 'staff_name' => 'Banu', 'salary_per_hr' => 120, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['2026-10-01', 8.5, 850], ['2026-10-02', 10, 1000]] as [$date, $hours, $salary]) {
        DB::table('pulse_attendance')->insert(['team_id' => $teamId, 'staff_id' => $akash, 'date' => $date, 'duration' => $hours, 'salary' => $salary, 'created_at' => now(), 'updated_at' => now()]);
    }

    return [$akash, $banu];
}

it('returns the web report dataset with present, absent, hours and salary', function () {
    [$akash] = seedReportAttendance($this->teamId);
    Sanctum::actingAs($this->users['supervisor']);
    $rows = collect($this->getJson('/api/attendance/report/summary?start=2026-10-01&end=2026-10-03')->assertOk()->json('worker_summary'))->keyBy('staff_name');

    expect($rows['Akash']['staff_id'])->toBe($akash)
        ->and($rows['Akash']['present_days'])->toBe(2)
        ->and($rows['Akash']['absent_days'])->toBe(1)
        ->and((float) $rows['Akash']['total_duration'])->toBe(18.5)
        ->and((float) $rows['Akash']['total_salary'])->toBe(1850.0)
        ->and($rows['Banu']['present_days'])->toBe(0)
        ->and($rows['Banu']['absent_days'])->toBe(3);
});

it('exports the attendance report PDF, optionally for chosen staff', function () {
    [$akash] = seedReportAttendance($this->teamId);
    Sanctum::actingAs($this->users['owner']);
    $this->get('/api/attendance/report/pdf?start=2026-10-01&end=2026-10-03')->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get('/api/attendance/report/pdf?start=2026-10-01&end=2026-10-03&staff_ids='.$akash)->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->getJson('/api/attendance/report/pdf?start=2026-10-01&end=2026-10-03&staff_ids=1;drop')->assertUnprocessable();
});

it('keeps the attendance report behind attendance.salary_report', function () {
    Sanctum::actingAs($this->users['reader']);
    $this->getJson('/api/attendance/report/summary?start=2026-10-01&end=2026-10-03')->assertForbidden();
    $this->getJson('/api/attendance/report/pdf?start=2026-10-01&end=2026-10-03')->assertForbidden();
});

it('rejects bad attendance ranges', function () {
    Sanctum::actingAs($this->users['owner']);
    $this->getJson('/api/attendance/report/summary?start=2026-10-03&end=2026-10-01')->assertUnprocessable();
    $this->getJson('/api/attendance/report/summary')->assertUnprocessable();
});
