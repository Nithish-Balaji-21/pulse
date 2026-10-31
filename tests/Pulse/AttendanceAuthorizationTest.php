<?php

use App\Livewire\Pulse\Attendance\AttendanceManager;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Pulse\Support\TeamFactory;

/**
 * Attendance is a supervisor-tier module: staff record production but do not mark
 * attendance, and readers do nothing at all. Both must be kept out of the add and
 * edit paths — the calendar link that opens the editor, and the save behind it.
 */
beforeEach(function () {
    $this->teamId = TeamFactory::create('Attendance Factory '.uniqid());

    $this->users = [];

    foreach (['reader', 'staff', 'supervisor'] as $role) {
        $user = User::factory()->create([
            'is_superadmin' => 0,
            'is_active' => 1,
            'current_team_id' => $this->teamId,
        ]);

        DB::table('team_user')->insert([
            ['team_id' => $this->teamId, 'user_id' => $user->id, 'role' => $role],
        ]);

        $this->users[$role] = $user;
    }

    $this->staffMember = Staff::create([
        'team_id' => $this->teamId,
        'staff_name' => 'Attendance Test Hand',
        'salary_per_hr' => 100,
        'is_active' => 1,
    ]);

    $this->date = now()->toDateString();

    app()->instance('current.team', Team::find($this->teamId));
});

afterEach(function () {
    DB::table('pulse_attendance')->where('team_id', $this->teamId)->delete();
    Staff::where('team_id', $this->teamId)->delete();

    app()->forgetInstance('current.team');
});

/**
 * The calendar feed carries the "Add / Edit Attendance" cell, which is the only
 * entry point to the editor — no cell, no add or edit button.
 */
it('keeps the add and edit attendance link out of the calendar for readers and staff', function (string $role) {
    $this->actingAs($this->users[$role]);

    $response = $this->get("/{$this->teamId}/attendance/resourcesAndAttendance?start={$this->date}&end={$this->date}");

    $response->assertSuccessful();

    expect(collect($response->json('events'))->pluck('extendedProps.editLink')->filter())->toBeEmpty();
})->with(['reader', 'staff']);

it('still offers the add and edit attendance link to a supervisor', function () {
    $this->actingAs($this->users['supervisor']);

    $response = $this->get("/{$this->teamId}/attendance/resourcesAndAttendance?start={$this->date}&end={$this->date}");

    $response->assertSuccessful();

    expect(collect($response->json('events'))->pluck('extendedProps.editLink')->filter())->not->toBeEmpty();
});

it('closes the attendance editor route to readers and staff', function (string $role) {
    $this->actingAs($this->users[$role]);

    $this->get("/{$this->teamId}/attendance/edit/{$this->date}")->assertForbidden();
})->with(['reader', 'staff']);

/**
 * The route middleware only guards the page load; Livewire updates post to
 * /livewire/update, so the save is callable on its own.
 */
it('refuses an attendance save from staff', function () {
    $this->actingAs($this->users['staff']);

    Livewire::test(AttendanceManager::class, ['date' => $this->date])
        ->set('staffsAttendance.0.duration', 8)
        ->call('saveAttendance');

    expect(DB::table('pulse_attendance')->where('team_id', $this->teamId)->count())->toBe(0);
});

it('lets a supervisor save attendance', function () {
    $this->actingAs($this->users['supervisor']);

    Livewire::test(AttendanceManager::class, ['date' => $this->date])
        ->set('staffsAttendance.0.duration', 8)
        ->call('saveAttendance');

    expect(DB::table('pulse_attendance')->where('team_id', $this->teamId)->count())->toBe(1);
});
