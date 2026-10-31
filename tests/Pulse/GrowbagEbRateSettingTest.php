<?php

use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * Growbag factories were created without a RS_PER_EB_UNIT row, so
 * ProcessDeviceDataForGrowBagsJob fell back to 0 and every summary row recorded no EB cost. These
 * cover the backfill migration and the Settings screen that has to expose the row once it exists.
 */
function makeGrowbagRateTeam(string $name, bool $growbags = true, bool $pith = false): int
{
    return TeamFactory::create($name, false, [
        'modules' => [
            'pith' => ['is_active' => $pith, 'submodules' => ['blocks' => false, 'bales' => false]],
            'fibre' => ['is_active' => false, 'submodules' => ['weight' => false]],
            'growbags' => ['is_active' => $growbags],
            'husks_stock' => ['is_active' => false],
            'attendance' => ['is_active' => false],
        ],
    ]);
}

function ebRateFor(int $teamId): ?object
{
    return DB::table('pulse_settings')
        ->where('team_id', $teamId)
        ->where('key', 'RS_PER_EB_UNIT')
        ->first();
}

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_08_15_171357_seed_rs_per_eb_unit_for_growbag_factories.php');
});

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('seeds the EB rate for a growbag factory that has none', function () {
    $teamId = makeGrowbagRateTeam('Growbag Rate Factory '.uniqid());

    expect(ebRateFor($teamId))->toBeNull();

    $this->migration->up();

    $setting = ebRateFor($teamId);

    expect($setting)->not->toBeNull()
        ->and($setting->module)->toBe('growbags')
        ->and($setting->value)->toBe('9.50')
        ->and($setting->text)->toBe('Rupees per EB unit');
});

it('leaves a factory that already carries the rate alone', function () {
    // A growbag factory that also runs pith already has the rate under the pith module, and the
    // jobs read it without filtering on module — a second row would be one too many.
    $teamId = makeGrowbagRateTeam('Growbag Plus Pith Factory '.uniqid(), pith: true);

    DB::table('pulse_settings')->insert([
        'team_id' => $teamId, 'module' => 'pith', 'key' => 'RS_PER_EB_UNIT',
        'text' => 'Rupees per EB unit', 'value' => '11.25', 'options' => '',
    ]);

    $this->migration->up();

    expect(DB::table('pulse_settings')->where('team_id', $teamId)->where('key', 'RS_PER_EB_UNIT')->count())->toBe(1)
        ->and(ebRateFor($teamId)->value)->toBe('11.25');
});

it('does not touch a factory without the growbags module', function () {
    $teamId = makeGrowbagRateTeam('No Growbags Factory '.uniqid(), growbags: false, pith: true);

    $this->migration->up();

    expect(ebRateFor($teamId))->toBeNull();
});

it('is safe to re-run', function () {
    $teamId = makeGrowbagRateTeam('Growbag Rerun Factory '.uniqid());

    $this->migration->up();
    $this->migration->up();

    expect(DB::table('pulse_settings')->where('team_id', $teamId)->where('key', 'RS_PER_EB_UNIT')->count())->toBe(1);
});

it('exposes the growbags rate on the settings screen, where it can be corrected', function () {
    $teamId = makeGrowbagRateTeam('Growbag Settings Factory '.uniqid());
    $this->migration->up();

    // Factory Customizations is an owner-only screen (see config/pulse_role_permissions.php).
    $owner = User::factory()->create([
        'is_superadmin' => 0, 'is_active' => 1, 'current_team_id' => $teamId,
    ]);
    DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $owner->id, 'role' => 'owner']);

    app()->instance('current.team', Team::find($teamId));
    TeamRolePermissions::syncOnModuleChange($teamId);

    $this->actingAs($owner)
        ->get("/{$teamId}/settings")
        ->assertSuccessful()
        ->assertSee('RS_PER_EB_UNIT', false);
});
