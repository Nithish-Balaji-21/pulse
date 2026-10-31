<?php

use App\Console\Traits\StaggersTeamDispatch;
use App\Jobs\Pulse\ProcessDeviceDataForPithJob;
use Carbon\Carbon;

function dispatchScheduler(): object
{
    return new class
    {
        use StaggersTeamDispatch;

        public function delay(string $startTime, int $bufferMinutes, int $position): Carbon
        {
            return $this->delayForTeam($startTime, $bufferMinutes, $position);
        }

        public function offset(int $position): int
        {
            return $this->staggerOffsetFor($position);
        }
    };
}

beforeEach(function () {
    config()->set('app.team_dispatch_stagger_secs', 30);
    config()->set('app.team_dispatch_window_mins', 30);
    Carbon::setTestNow('2026-08-18 00:10:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the first team runs at its shift anchor plus the module buffer', function () {
    expect(dispatchScheduler()->delay('08:00:00', 15, 0)->toDateTimeString())
        ->toBe('2026-08-18 08:15:00');
});

test('teams sharing a shift anchor are spread apart', function (int $position, string $expected) {
    expect(dispatchScheduler()->delay('08:00:00', 15, $position)->toDateTimeString())
        ->toBe($expected);
})->with([
    'second team' => [1, '2026-08-18 08:15:30'],
    'third team' => [2, '2026-08-18 08:16:00'],
    'fifth team' => [4, '2026-08-18 08:17:00'],
]);

test('each team is offset from its own anchor, not a shared one', function () {
    expect(dispatchScheduler()->delay('07:00:00', 15, 1)->toDateTimeString())
        ->toBe('2026-08-18 07:15:30');
});

test('no team is pushed beyond the stagger window, however many there are', function () {
    $scheduler = dispatchScheduler();

    foreach ([0, 5, 59, 60, 61, 299, 1_000] as $position) {
        expect($scheduler->offset($position))->toBeLessThan(30 * 60);
    }

    // 30 minute window at a 30 second gap is 60 slots, so team 61 shares team 1's slot.
    expect($scheduler->offset(60))->toBe($scheduler->offset(0))
        ->and($scheduler->offset(61))->toBe($scheduler->offset(1));
});

test('the last of three hundred teams still starts within 45 minutes of its anchor', function () {
    $latest = collect(range(0, 299))
        ->map(fn (int $position) => dispatchScheduler()->delay('08:00:00', 15, $position))
        ->max();

    expect(Carbon::parse('2026-08-18 08:00:00')->diffInMinutes($latest))
        ->toBeLessThanOrEqual(45);
});

test('nightly device data jobs retry with a jittered wait', function () {
    $job = new ProcessDeviceDataForPithJob(1, 1, ['2026-08-17']);

    expect($job->tries)->toBe(3);

    foreach (range(1, 20) as $ignored) {
        [$first, $second] = $job->backoff();

        expect($first)->toBeGreaterThanOrEqual(30)->toBeLessThanOrEqual(90)
            ->and($second)->toBeGreaterThanOrEqual(120)->toBeLessThanOrEqual(240);
    }
});
