<?php

/**
 * The mobile app's daily husk entry popup is gated on `husk_inventory:daily_reminder`, a
 * capability deliberately separate from the `husk_inventory:update` write it accompanies:
 * being able to record yesterday's intake and being the person interrupted each morning to
 * do it are different decisions, and an owner configures the second from the Roles page.
 *
 * By default the prompt belongs to staff — the people who weigh the incoming husks — and to
 * nobody else, superadmins included: holding every right in a factory is not a reason to be
 * handed its operators' morning chore.
 */

use App\Models\Pulse\Shift;
use App\Support\Pulse\TeamModuleContext;
use App\Support\Pulse\TeamRolePermissions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Pulse\Support\RbacFixtures;

beforeEach(function () {
    $this->teamId = RbacFixtures::team('Husk Reminder Factory');

    Shift::forgetAnchorCache($this->teamId);

    DB::table('pulse_shifts')->insert([
        'team_id' => $this->teamId,
        'name' => 'Day',
        'is_active' => 1,
        'start_time' => '08:00:00',
        'end_time' => '20:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Well past the anchor (08:00) + DAILY_HUKS_CRON_INTERVAL_MINS trigger window, so the
    // endpoint's time guard is never what decides these assertions.
    Carbon::setTestNow(Carbon::today()->setTime(11, 0));

    // Yesterday's row exists with husks_received still null — the pending state the popup exists for.
    DB::table('pulse_husks_daily_stock')->insert([
        'team_id' => $this->teamId,
        'date' => Carbon::yesterday()->toDateString(),
        'opening_stock' => 1000,
        'closing_stock' => 1000,
        'cft' => 0,
        'husks_received' => null,
        'husks_correction' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    Shift::forgetAnchorCache($this->teamId);
    RbacFixtures::forgetTeam();
});

it('grants the daily reminder to staff only by default', function () {
    $ctx = TeamModuleContext::fromTeamId($this->teamId);

    $staff = TeamRolePermissions::buildDefaultPermissionsForRole('staff', $ctx);
    $supervisor = TeamRolePermissions::buildDefaultPermissionsForRole('supervisor', $ctx);
    $admin = TeamRolePermissions::buildDefaultPermissionsForRole('admin', $ctx);
    $owner = TeamRolePermissions::buildDefaultPermissionsForRole('owner', $ctx);

    expect($staff['husk_inventory']['daily_reminder'])->toBeTrue()
        // The write travels with the prompt — staff must be able to post what they are asked for.
        ->and($staff['husk_inventory']['update'])->toBeTrue()
        // Everyone above them keeps the write, but is no longer interrupted by the popup.
        ->and((bool) ($supervisor['husk_inventory']['daily_reminder'] ?? false))->toBeFalse()
        ->and($supervisor['husk_inventory']['update'])->toBeTrue()
        ->and((bool) ($admin['husk_inventory']['daily_reminder'] ?? false))->toBeFalse()
        ->and($admin['husk_inventory']['update'])->toBeTrue()
        ->and((bool) ($owner['husk_inventory']['daily_reminder'] ?? false))->toBeFalse()
        ->and($owner['husk_inventory']['update'])->toBeTrue();
});

it('shows the checklist to staff with a pending entry', function () {
    Sanctum::actingAs(RbacFixtures::user($this->teamId, 'staff'));

    $this->getJson('/api/husks-daily-stock/show-daily-checklist')
        ->assertOk()
        ->assertJsonPath('show_checklist', true);
});

it('does not show the checklist to roles that hold only the write', function (string $role) {
    Sanctum::actingAs(RbacFixtures::user($this->teamId, $role));

    $this->getJson('/api/husks-daily-stock/show-daily-checklist')
        ->assertOk()
        ->assertJsonPath('show_checklist', false);
})->with(['supervisor', 'admin', 'owner']);

it('does not show the checklist to a superadmin', function () {
    // The superadmin bypass in User::allows() hands out every right in every factory, which had
    // been enough to trip the popup. A reminder is not a right, so it resolves from the role
    // matrix instead — and a superadmin sitting in the team as an admin holds no reminder.
    Sanctum::actingAs(RbacFixtures::user($this->teamId, 'admin', superadmin: true));

    $this->getJson('/api/husks-daily-stock/show-daily-checklist')
        ->assertOk()
        ->assertJsonPath('show_checklist', false);
});

it('stops showing the checklist when an owner revokes the reminder from staff', function () {
    RbacFixtures::setPermissions($this->teamId, 'staff', [
        'husk_inventory:view',
        'husk_inventory:update',
    ]);

    Sanctum::actingAs(RbacFixtures::user($this->teamId, 'staff'));

    $this->getJson('/api/husks-daily-stock/show-daily-checklist')
        ->assertOk()
        ->assertJsonPath('show_checklist', false);
});

it('does not show the checklist to a role that cannot submit the entry', function () {
    // Reminder without the write is meaningless — the popup would have nothing to post to.
    RbacFixtures::setPermissions($this->teamId, 'reader', [
        'husk_inventory:view',
        'husk_inventory:daily_reminder',
    ]);

    Sanctum::actingAs(RbacFixtures::user($this->teamId, 'reader'));

    $this->getJson('/api/husks-daily-stock/show-daily-checklist')
        ->assertOk()
        ->assertJsonPath('show_checklist', false);
});

it('stops showing the checklist once the entry is filled in', function () {
    DB::table('pulse_husks_daily_stock')
        ->where('team_id', $this->teamId)
        ->update(['husks_received' => 250]);

    Sanctum::actingAs(RbacFixtures::user($this->teamId, 'staff'));

    $this->getJson('/api/husks-daily-stock/show-daily-checklist')
        ->assertOk()
        ->assertJsonPath('show_checklist', false);
});
