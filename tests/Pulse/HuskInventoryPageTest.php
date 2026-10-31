<?php

use App\Livewire\Pulse\Pith\HuskInventory;
use App\Models\Pulse\HusksDailyStock;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Pulse\Support\RbacFixtures;

/**
 * The Husk Inventory page: yesterday's summary, a month of daily rows as a list or chart, and
 * the Update Stock form, whose saves reconcile every later day through reconcile_husks_stock.
 */
beforeEach(function () {
    $this->travelTo('2026-09-23 10:00:00');
    $this->teamId = RbacFixtures::team('Husk Page');
    RbacFixtures::bindTeam($this->teamId);
    $this->users = RbacFixtures::usersForAllRoles($this->teamId);

    DB::table('pulse_settings')->insert(['team_id' => $this->teamId, 'module' => 'pith', 'key' => 'HUSKS_PER_CFT', 'value' => '2']);
});

afterEach(function () {
    RbacFixtures::forgetTeam();
});

function huskDay(int $teamId, string $date, float $opening, ?float $received, float $closing, float $correction = 0, ?string $comment = null): HusksDailyStock
{
    DB::table('pulse_husks_daily_stock')->insert([
        'team_id' => $teamId,
        'date' => $date,
        'opening_stock' => $opening,
        'husks_received' => $received,
        'cft' => 0,
        'closing_stock' => $closing,
        'husks_correction' => $correction,
        'correction_comment' => $comment,
    ]);

    return HusksDailyStock::where('team_id', $teamId)->where('date', $date)->firstOrFail();
}

function huskPage(string $role)
{
    test()->actingAs(test()->users[$role]);

    return Livewire::test(HuskInventory::class);
}

it('summarises yesterday and lists the month newest first, marking days with no row', function () {
    huskDay($this->teamId, '2026-09-20', 1000, 500, 1300);
    huskDay($this->teamId, '2026-09-22', 1300, 200, 1100, correction: -100, comment: 'Wet husks');

    $page = huskPage('reader')
        ->assertSee('Yesterday · '.now()->subDay()->format(config('app.date_format')).' · cft')
        ->assertSee('Wet husks')
        ->assertSee('No record')
        ->assertDontSee('YESTERDAY’S ENTRY PENDING')
        ->assertDontSee('Update Stock');

    $days = $page->viewData('month')['days'];

    expect($days)->toHaveCount(22)
        ->and($days->first()['date'])->toBe('2026-09-22')
        ->and($days->first()['consumed'])->toBe(300.0)
        ->and($days->firstWhere('date', '2026-09-21')['has_row'])->toBeFalse()
        ->and($page->viewData('month')['totals'])->toMatchArray(['received' => 700.0, 'consumed' => 500.0, 'correction' => -100.0, 'stock' => 1100.0]);
});

it('writes dates in the app date format, without the weekday', function () {
    config(['app.date_format' => 'd.m.Y']);
    huskDay($this->teamId, '2026-09-19', 900, 300, 1000);
    huskDay($this->teamId, '2026-09-22', 1000, null, 1000);

    $page = huskPage('staff')
        ->assertSee('Husks received on 22.09.2026 not entered yet')
        ->assertSee('on 19.09.2026')
        ->assertDontSee('Tue')
        ->assertDontSee('22 Sep 2026');

    expect($page->viewData('chart')['labels'][21])->toBe('22.09.2026');

    $page->call('openUpdateModal')
        ->assertSee('22.09.2026 · Opening 1,000 cft')
        ->assertSee('Last entry: 300 cft on 19.09.2026');
});

it('carries the last known stock across days with no row in the chart', function () {
    huskDay($this->teamId, '2026-08-31', 800, 0, 900);
    huskDay($this->teamId, '2026-09-03', 900, 100, 950);

    $stock = huskPage('reader')->viewData('chart')['series']['stock'];

    expect(array_slice($stock, 0, 4))->toBe([900.0, 900.0, 950.0, 950.0]);
});

it('flags yesterday as pending until husks received is entered', function () {
    huskDay($this->teamId, '2026-09-22', 1000, null, 1000);

    huskPage('staff')
        ->assertSee('YESTERDAY’S ENTRY PENDING')
        ->assertSee('Pending')
        ->assertSee('Enter now');
});

it('creates yesterday’s row on Update Stock and reconciles it on save', function () {
    huskDay($this->teamId, '2026-09-21', 1000, 0, 1000);
    DB::table('pulse_summary_pith_productions')->insert([
        'team_id' => $this->teamId, 'shift_date' => '2026-09-22', 'shift_id' => 1, 'shift_name' => 'Day',
        'start_time' => '2026-09-22 08:00:00', 'end_time' => '2026-09-22 20:00:00', 'cft_produced' => 100,
    ]);

    huskPage('staff')
        ->call('openUpdateModal')
        ->assertSet('husksReceived', null)
        ->assertSee('Opening 1,000 cft')
        ->set('husksReceived', 500)
        ->set('correctionType', 'deduct')
        ->set('correctionAmount', 50)
        ->set('correctionComment', 'Recount')
        ->call('updateStock')
        ->assertHasNoErrors()
        ->assertSet('stockId', null)
        ->assertDispatched('pi-toast');

    $row = HusksDailyStock::where('team_id', $this->teamId)->where('date', '2026-09-22')->firstOrFail();

    // 1000 opening + 500 received − 50 correction − (100 cft pith × 2 husks per cft)
    expect((float) $row->closing_stock)->toBe(1250.0)
        ->and((float) $row->husks_correction)->toBe(-50.0)
        ->and($row->correction_comment)->toBe('Recount')
        ->and($row->user_id)->toBe($this->users['staff']->id);
});

it('offers editing on the latest record only, whatever day it falls on', function () {
    huskDay($this->teamId, '2026-09-19', 1000, 0, 1000);
    $latest = huskDay($this->teamId, '2026-09-20', 1000, 300, 1300);
    // 21 and 22 Sep have no rows yet, so 20 Sep is the latest record.

    $page = huskPage('supervisor')
        ->assertSeeHtml("editLatest('2026-09-20')")
        ->assertDontSeeHtml("editLatest('2026-09-19')")
        ->assertDontSeeHtml("editLatest('2026-09-22')");

    expect($page->viewData('chart')['editable'])->toBe([...array_fill(0, 19, false), true, false, false]);

    $page->call('editLatest', '2026-09-19')
        ->assertSet('stockId', null)
        ->call('editLatest', '2026-09-20')
        ->assertSet('stockId', $latest->id)
        ->assertSet('husksReceived', 300.0)
        ->set('husksReceived', 450)
        ->call('updateStock')
        ->assertHasNoErrors();

    expect((float) $latest->fresh()->closing_stock)->toBe(1450.0);
});

it('moves the edit to yesterday once Update Stock creates its row', function () {
    huskDay($this->teamId, '2026-09-20', 1000, 300, 1300);

    huskPage('staff')
        ->call('openUpdateModal')
        ->call('closeUpdateModal')
        ->assertSeeHtml("editLatest('2026-09-22')")
        ->assertDontSeeHtml("editLatest('2026-09-20')");
});

it('rejects a save once a newer record has been added', function () {
    huskDay($this->teamId, '2026-09-20', 1000, 0, 1000);
    $latest = huskDay($this->teamId, '2026-09-21', 1000, null, 1000);

    $page = huskPage('staff')->call('editLatest', '2026-09-21')->set('husksReceived', 400);

    // husks:daily-stock adds yesterday's row while the form is still open.
    huskDay($this->teamId, '2026-09-22', 1000, null, 1000);

    $page->call('updateStock')
        ->assertDispatched('pi-toast', error: true)
        ->assertSet('stockId', null);

    expect($latest->fresh()->husks_received)->toBeNull();
});

it('ignores a row dated today when finding the latest record', function () {
    $yesterday = huskDay($this->teamId, '2026-09-22', 1000, 100, 1100);
    huskDay($this->teamId, '2026-09-23', 1100, null, 1100);

    huskPage('staff')
        ->call('editLatest', '2026-09-22')
        ->assertSet('stockId', $yesterday->id);
});

it('requires husks received', function () {
    huskDay($this->teamId, '2026-09-22', 1000, null, 1000);

    huskPage('staff')
        ->call('openUpdateModal')
        ->call('updateStock')
        ->assertHasErrors(['husksReceived' => 'required']);
});

it('keeps readers out of the form', function () {
    $row = huskDay($this->teamId, '2026-09-22', 1000, null, 1000);

    huskPage('reader')
        ->call('openUpdateModal')
        ->assertSet('stockId', null)
        ->call('updateStock')
        ->assertDispatched('pi-toast', error: true);

    expect($row->fresh()->husks_received)->toBeNull();
});

it('does not let the client point the form at another row', function () {
    $otherTeam = RbacFixtures::team('Other Husk Factory');
    $foreign = huskDay($otherTeam, '2026-09-22', 1000, null, 1000);

    huskPage('staff')->set('stockId', $foreign->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('moves between months without going past the current one', function () {
    DB::table('teams')->where('id', $this->teamId)->update(['created_at' => '2026-08-10 09:00:00']);
    RbacFixtures::bindTeam($this->teamId);

    huskPage('reader')
        ->call('nextMonth')
        ->assertSet('selectedMonth', '2026-09')
        ->call('previousMonth')
        ->assertSet('selectedMonth', '2026-08')
        ->assertSee('Aug 2026')
        ->call('previousMonth')
        ->assertSet('selectedMonth', '2026-08');
});

it('jumps to a month picked from the month picker, within the team’s range', function () {
    DB::table('teams')->where('id', $this->teamId)->update(['created_at' => '2025-11-10 09:00:00']);
    RbacFixtures::bindTeam($this->teamId);

    huskPage('reader')
        ->call('selectMonth', '2026-02')
        ->assertSet('selectedMonth', '2026-02')
        ->assertSee('Feb 2026')
        ->call('selectMonth', '2025-12')
        ->assertSet('selectedMonth', '2025-12')
        ->call('selectMonth', '2025-10') // before the team existed
        ->assertSet('selectedMonth', '2025-12')
        ->call('selectMonth', '2026-10') // future
        ->assertSet('selectedMonth', '2025-12')
        ->call('selectMonth', '2026-13')
        ->assertSet('selectedMonth', '2025-12');
});
