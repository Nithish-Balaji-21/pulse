<?php

use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Pulse\Support\RbacFixtures;

function expenseEmbedTeam(string $name): int
{
    $attributes = ['name' => $name, 'created_at' => now(), 'updated_at' => now()];
    if (Schema::hasColumn('teams', 'user_id')) {
        $attributes['user_id'] = User::factory()->create()->id;
    }
    if (Schema::hasColumn('teams', 'personal_team')) {
        $attributes['personal_team'] = false;
    }
    $id = (int) DB::table('teams')->insertGetId($attributes);
    DB::table('team_pulse')->insert([
        'team_id' => $id, 'is_active' => 1, 'license_valid_till' => now()->addYear()->toDateString(),
        'modules' => json_encode(['pulse' => ['is_active' => true, 'modules' => [
            'pith' => ['is_active' => true, 'submodules' => ['bales' => true, 'blocks' => true]],
            'attendance' => ['is_active' => true],
        ]]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    TeamRolePermissions::seedDefaultsForTeam($id);

    return $id;
}

beforeEach(function () {
    $this->teamId = expenseEmbedTeam('Alam Cocos');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);
    config(['expenses.url' => 'http://localhost:8000', 'expenses.api_key' => str_repeat('k', 64)]);
    Cache::flush();
    Http::fake(['*/api/pulse/alam-cocos/details*' => Http::response(['connected' => true, 'company' => 'alam_cocos', 'staff' => ['columns' => [], 'rows' => [], 'column_totals' => [], 'grand_total' => 150], 'charges' => [], 'totals' => ['staff' => 150, 'other' => 0, 'total' => 150]]), '*/api/pulse/alam-cocos/connection' => Http::response(['connected' => true, 'company' => 'alam_cocos']), '*/api/pulse/alam-cocos/expenses*' => Http::response(['connected' => true, 'company' => 'alam_cocos', 'rows' => [['date' => '2026-10-02', 'cost_center' => 'Alam Cocos', 'hourly_cost' => 100, 'activity_cost' => 20, 'materials_vehicles_cost' => 30, 'total_cost' => 150]], 'totals' => ['total_cost' => 150]])]);
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

it('renders Alam Cocos expenses directly in Pulse with no iframe or editing for every role', function () {
    foreach (['owner', 'admin', 'reader'] as $role) {
        $this->actingAs($this->users[$role])->get('/'.$this->teamId.'/expenses?from=2026-10-01&to=2026-10-08')
            ->assertOk()->assertSee('Staff Expense · Cost by Task')->assertSee('150.00')->assertDontSee('<iframe', false);
    }
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/api/pulse/alam-cocos/details') && $request['from'] === '2026-10-01');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('does not call Expenses or show its menu for any other Pulse factory', function () {
    $other = expenseEmbedTeam('Hexagro Fibre');
    DB::table('team_user')->insert(['team_id' => $other, 'user_id' => $this->users['owner']->id, 'role' => 'owner']);
    Http::fake();
    $this->actingAs($this->users['owner'])->get('/'.$other.'/expenses')->assertOk()
        ->assertSee('Expenses is not connected for this factory')->assertDontSee('short="Expenses"', false);
    Http::assertNothingSent();
});

it('handles an unavailable Alam company without a 404 or exposed server error', function () {
    Http::swap(new Factory);
    Cache::flush();
    Http::fake(['*/api/pulse/alam-cocos/details*' => Http::response(['connected' => false, 'company' => 'alam_cocos']), '*/api/pulse/alam-cocos/connection' => Http::response(['connected' => false, 'company' => 'alam_cocos']), '*/api/pulse/alam-cocos/expenses*' => Http::response(['connected' => false, 'company' => 'alam_cocos'])]);
    $this->actingAs($this->users['reader'])->get('/'.$this->teamId.'/expenses')->assertOk()
        ->assertSee('Expenses is not connected for this factory')->assertDontSee('short="Expenses"', false);
});

it('rejects invalid date ranges before requesting expense data', function () {
    $this->actingAs($this->users['reader'])->getJson('/'.$this->teamId.'/expenses?from=2026-10-08&to=2026-10-01')->assertUnprocessable();
});

it('returns widget totals from Expenses for the exact selected period', function () {
    $this->actingAs($this->users['reader'])->getJson('/'.$this->teamId.'/expenses/summary?from=2026-10-01&to=2026-10-08')
        ->assertOk()->assertJsonPath('connected', true)->assertJsonPath('totals.total_cost', 150)->assertJsonPath('has_entries', true);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/api/pulse/alam-cocos/expenses') && $request['from'] === '2026-10-01' && $request['to'] === '2026-10-08');
});

it('renders the widget for connected Alam and hides it for another factory', function () {
    $this->actingAs($this->users['reader']);
    expect(view('pulse::dashboard.expense-widget')->render())->toContain('expense-summary-card', 'window.loadAlamExpenseWidget', 'Materials & vehicles');
    RbacFixtures::bindTeam(expenseEmbedTeam('Hexagro Fibre'));
    expect(trim(view('pulse::dashboard.expense-widget')->render()))->toBe('');
});

it('returns an unavailable state rather than invented zero amounts', function () {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response([], 503)]);
    $this->actingAs($this->users['reader'])->getJson('/'.$this->teamId.'/expenses/summary?from=2026-10-01&to=2026-10-08')
        ->assertOk()->assertExactJson(['connected' => false]);
});

it('keeps both expense tabs and the date range when switching', function () {
    foreach (['staff' => 'Staff Expense · Cost by Task', 'other' => 'Other Expense · Vehicles & Materials'] as $tab => $heading) {
        $this->actingAs($this->users['reader'])->get('/'.$this->teamId.'/expenses?from=2026-09-01&to=2026-09-30&tab='.$tab)
            ->assertOk()->assertSee($heading)->assertDontSee('>Overview<', false)->assertSee('Staff Expense')->assertSee('Other Expense')
            ->assertSee('2026-09-01')->assertDontSee('Add New Charge')->assertDontSee('Read-only');
    }
});

it('renders the Reports expense design and filters API rows without double counting', function () {
    Http::swap(new Factory);
    Http::fake([
        '*/connection' => Http::response(['connected' => true, 'company' => 'alam_cocos']),
        '*/details*' => Http::response(['connected' => true, 'company' => 'alam_cocos',
            'staff' => ['columns' => [['key' => 'hours', 'label' => 'Packing', 'group' => 'hourly']], 'rows' => [['name' => 'Test worker', 'cells' => ['hours' => ['amount' => 100, 'hours' => 2, 'quantity' => 0]]]], 'column_totals' => []],
            'charges' => [['type' => 'vehicle', 'item' => 'Test vehicle', 'notes' => 'Trip', 'date' => '2026-09-02', 'paid_by' => 'Alam Cocos', 'billed_to' => 'Hexagro', 'amount' => 30]],
            'totals' => ['staff' => 100, 'other' => 30, 'total' => 130]]),
    ]);
    $url = '/'.$this->teamId.'/reports/expenses?from=2026-09-01&to=2026-09-30&timezone=Asia/Kolkata';
    $this->actingAs($this->users['reader'])->get($url)->assertOk()->assertSee('Expense Report')->assertSee('Export PDF')->assertSee('Test worker')->assertSee('Test vehicle')->assertSee('Asia/Kolkata')->assertViewHas('filteredTotal', 130.0);
    $this->get($url.'&category=vehicle')->assertOk()->assertSee('Test vehicle')->assertDontSee('Test worker')->assertViewHas('filteredTotal', 30.0);
    $this->get($url.'&category=material')->assertOk()->assertSee('No expenses recorded')->assertViewHas('filteredTotal', 0);
    $this->get($url.'&export=pdf&category=vehicle')->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->getJson($url.'&category=invalid')->assertUnprocessable();
    $this->getJson('/'.$this->teamId.'/reports/expenses?timezone=NotAZone')->assertUnprocessable();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/details') && $request['from'] === '2026-09-01' && $request['to'] === '2026-09-30');
});
