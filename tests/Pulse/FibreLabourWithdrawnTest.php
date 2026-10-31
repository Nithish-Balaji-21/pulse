<?php

use App\Models\Team;
use App\Models\User;
use App\Support\Pulse\TeamModuleContext;
use App\Support\Pulse\TeamRolePermissions;
use Illuminate\Support\Facades\DB;
use Tests\Pulse\Support\TeamFactory;

/**
 * Labour is withdrawn from device-only factories — fibre and/or growbags with no pith. The rule
 * has to hold at three levels — the nav flag, the role matrix, and the route — and must not touch
 * a factory that also runs pith.
 */
function makeLabourTeam(bool $fibre, bool $pith, bool $growbags = false): int
{
    return TeamFactory::create('Labour Rule '.uniqid(), false, [
        'modules' => [
            'pith' => ['is_active' => $pith, 'submodules' => ['blocks' => false, 'bales' => false]],
            'fibre' => ['is_active' => $fibre, 'submodules' => ['weight' => true]],
            'growbags' => ['is_active' => $growbags],
            'husks_stock' => ['is_active' => false],
            'attendance' => ['is_active' => true],
        ],
    ]);
}

function makeLabourUser(int $teamId, string $role = 'admin', bool $superadmin = false): User
{
    $user = User::factory()->create([
        'is_superadmin' => $superadmin ? 1 : 0,
        'is_active' => 1,
        'current_team_id' => $teamId,
    ]);

    DB::table('team_user')->insert(['team_id' => $teamId, 'user_id' => $user->id, 'role' => $role]);

    app()->instance('current.team', Team::find($teamId));
    TeamRolePermissions::syncOnModuleChange($teamId);

    return $user;
}

afterEach(function () {
    app()->forgetInstance('current.team');
});

it('withdraws the attendance module from a fibre-only factory even with the stored flag on', function () {
    $teamId = makeLabourTeam(fibre: true, pith: false);
    makeLabourUser($teamId);

    // The stored JSON still says active — the rule is what withdraws it.
    expect(Team::find($teamId)->pulse->modules['pulse']['modules']['attendance']['is_active'])->toBeTrue()
        ->and(Team::find($teamId)->has_attendance_module)->toBeFalse()
        ->and(Team::find($teamId)->is_fibre_only_factory)->toBeTrue()
        ->and(TeamModuleContext::fromTeamId($teamId)->isModuleVisible('attendance'))->toBeFalse();
});

it('keeps Labour for a pith factory and for a factory running both modules', function () {
    foreach ([[false, true], [true, true]] as [$fibre, $pith]) {
        $teamId = makeLabourTeam(fibre: $fibre, pith: $pith);
        makeLabourUser($teamId);

        expect(Team::find($teamId)->has_attendance_module)->toBeTrue()
            ->and(Team::find($teamId)->is_fibre_only_factory)->toBeFalse()
            ->and(TeamModuleContext::fromTeamId($teamId)->isModuleVisible('attendance'))->toBeTrue();

        app()->forgetInstance('current.team');
    }
});

it('hides the Staff Attendance nav item for a fibre-only factory but keeps it for a pith factory', function () {
    $fibreTeam = makeLabourTeam(fibre: true, pith: false);
    $fibreAdmin = makeLabourUser($fibreTeam);

    $this->actingAs($fibreAdmin)
        ->get("/{$fibreTeam}/dashboard/fibre")
        ->assertSuccessful()
        ->assertDontSee('>Staff Attendance<', false);

    app()->forgetInstance('current.team');

    $pithTeam = makeLabourTeam(fibre: false, pith: true);
    $pithAdmin = makeLabourUser($pithTeam);

    $this->actingAs($pithAdmin)
        ->get("/{$pithTeam}/staffs")
        ->assertSuccessful()
        ->assertSee('Staff Attendance', false);
});

it('blocks the attendance routes for a fibre-only factory', function () {
    $teamId = makeLabourTeam(fibre: true, pith: false);
    $admin = makeLabourUser($teamId);

    $this->actingAs($admin)->get("/{$teamId}/attendance")->assertForbidden();
});

it('blocks them for a superadmin too, who otherwise bypasses the role matrix', function () {
    $teamId = makeLabourTeam(fibre: true, pith: false);
    $superadmin = makeLabourUser($teamId, 'owner', superadmin: true);

    expect($superadmin->canViewAttendance())->toBeFalse()
        ->and($superadmin->allows('attendance', 'view'))->toBeFalse()
        ->and($superadmin->allows('attendance', 'salary_report'))->toBeFalse();

    $this->actingAs($superadmin)->get("/{$teamId}/attendance")->assertForbidden();
});

it('leaves a superadmin full attendance access on a pith factory', function () {
    $teamId = makeLabourTeam(fibre: false, pith: true);
    $superadmin = makeLabourUser($teamId, 'owner', superadmin: true);

    expect($superadmin->canViewAttendance())->toBeTrue()
        ->and($superadmin->allows('attendance', 'view'))->toBeTrue();

    $this->actingAs($superadmin)->get("/{$teamId}/attendance")->assertSuccessful();
});

it('drops attendance capabilities from every role when permissions are re-synced', function () {
    $teamId = makeLabourTeam(fibre: true, pith: false);
    makeLabourUser($teamId);

    TeamRolePermissions::syncOnModuleChange($teamId);

    $roles = DB::table('pulse_team_roles')->where('team_id', $teamId)->pluck('permissions');

    expect($roles)->not->toBeEmpty();

    foreach ($roles as $permissions) {
        expect(json_decode($permissions, true)['attendance'] ?? [])->toBe([]);
    }
});

/**
 * Growbags is the second device-counted line, so the same rule reaches it: a growbag-only
 * factory has no per-head attendance to record either.
 */
it('withdraws the attendance module from a growbag-only factory', function () {
    $teamId = makeLabourTeam(fibre: false, pith: false, growbags: true);
    makeLabourUser($teamId);

    // The stored JSON still says active — the rule is what withdraws it.
    expect(Team::find($teamId)->pulse->modules['pulse']['modules']['attendance']['is_active'])->toBeTrue()
        ->and(Team::find($teamId)->has_attendance_module)->toBeFalse()
        ->and(Team::find($teamId)->is_device_only_factory)->toBeTrue()
        // Growbag factories still configure their own IoT devices, so the narrower fibre-only
        // rule that governs device config must not have widened with it.
        ->and(Team::find($teamId)->is_fibre_only_factory)->toBeFalse()
        ->and(TeamModuleContext::fromTeamId($teamId)->isModuleVisible('attendance'))->toBeFalse()
        ->and(TeamModuleContext::fromTeamId($teamId)->isModuleVisible('iot_devices'))->toBeTrue();
});

it('withdraws it from a factory running both device lines without pith', function () {
    $teamId = makeLabourTeam(fibre: true, pith: false, growbags: true);
    makeLabourUser($teamId);

    expect(Team::find($teamId)->has_attendance_module)->toBeFalse()
        ->and(Team::find($teamId)->is_device_only_factory)->toBeTrue();
});

it('keeps Labour for a growbag factory that also runs pith', function () {
    $teamId = makeLabourTeam(fibre: false, pith: true, growbags: true);
    makeLabourUser($teamId);

    expect(Team::find($teamId)->has_attendance_module)->toBeTrue()
        ->and(Team::find($teamId)->is_device_only_factory)->toBeFalse()
        ->and(TeamModuleContext::fromTeamId($teamId)->isModuleVisible('attendance'))->toBeTrue();
});

it('hides the Staff Attendance nav item and blocks the routes for a growbag-only factory', function () {
    $teamId = makeLabourTeam(fibre: false, pith: false, growbags: true);
    $admin = makeLabourUser($teamId);

    $this->actingAs($admin)
        ->get("/{$teamId}/dashboard/growbags")
        ->assertSuccessful()
        ->assertDontSee('>Staff Attendance<', false);

    $this->actingAs($admin)->get("/{$teamId}/attendance")->assertForbidden();
});

it('blocks growbag-only attendance for a superadmin too', function () {
    $teamId = makeLabourTeam(fibre: false, pith: false, growbags: true);
    $superadmin = makeLabourUser($teamId, 'owner', superadmin: true);

    expect($superadmin->canViewAttendance())->toBeFalse()
        ->and($superadmin->allows('attendance', 'view'))->toBeFalse();

    $this->actingAs($superadmin)->get("/{$teamId}/attendance")->assertForbidden();
});
