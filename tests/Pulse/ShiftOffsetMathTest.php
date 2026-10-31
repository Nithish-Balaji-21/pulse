<?php

use App\Models\Pulse\Shift;

it('round-trips clock times through offsets at non-08:00 anchors', function () {
    foreach (['06:00:00', '07:30:00', '08:00:00', '13:15:00'] as $anchor) {
        foreach ([0, 1, 90, 600, 1439] as $offset) {
            $clock = Shift::offsetToClock($offset, $anchor);

            expect(Shift::clockToOffset($clock, $anchor))->toBe($offset);
        }

        expect(Shift::clockToOffset($anchor, $anchor))->toBe(0)
            ->and(Shift::offsetToClock(0, $anchor))->toBe($anchor)
            ->and(Shift::offsetToClock(Shift::MINUTES_PER_DAY, $anchor))->toBe($anchor);
    }
});

it('maps an end equal to the anchor to offset 1440', function () {
    $shift = new Shift(['start_time' => '19:30:00', 'end_time' => '07:30:00']);

    expect($shift->offsetsFor('07:30:00'))->toBe([720, 1440]);
});

it('derives next-day flags from the anchor', function () {
    $overnight = new Shift(['start_time' => '22:00:00', 'end_time' => '06:00:00']);

    expect($overnight->startsNextDay('06:00:00'))->toBeFalse()
        ->and($overnight->endsNextDay('06:00:00'))->toBeTrue()
        ->and($overnight->startsNextDay('23:00:00'))->toBeTrue();

    $daytime = new Shift(['start_time' => '08:00:00', 'end_time' => '18:00:00']);

    expect($daytime->startsNextDay('08:00:00'))->toBeFalse()
        ->and($daytime->endsNextDay('08:00:00'))->toBeFalse();
});

it('treats shift boundaries as start-inclusive and end-exclusive', function () {
    $anchor = '08:00:00';
    $first = new Shift(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
    $second = new Shift(['start_time' => '18:00:00', 'end_time' => '08:00:00']);

    expect($first->containsMoment('2026-01-01 18:00:00', $anchor))->toBeFalse()
        ->and($second->containsMoment('2026-01-01 18:00:00', $anchor))->toBeTrue()
        ->and($second->containsMoment('2026-01-02 07:59:59', $anchor))->toBeTrue()
        ->and($second->containsMoment('2026-01-02 08:00:00', $anchor))->toBeFalse();
});

it('builds the physical window for an overnight shift', function () {
    $shift = new Shift(['start_time' => '18:00:00', 'end_time' => '08:00:00']);

    [$start, $end] = $shift->windowOn('2026-01-01', '08:00:00');

    expect($start->toDateTimeString())->toBe('2026-01-01 18:00:00')
        ->and($end->toDateTimeString())->toBe('2026-01-02 08:00:00');
});

it('places a fully next-day shift on the following calendar day', function () {
    $shift = new Shift(['start_time' => '00:00:00', 'end_time' => '06:00:00']);

    [$start, $end] = $shift->windowOn('2026-01-01', '08:00:00');

    expect($start->toDateTimeString())->toBe('2026-01-02 00:00:00')
        ->and($end->toDateTimeString())->toBe('2026-01-02 06:00:00');
});
