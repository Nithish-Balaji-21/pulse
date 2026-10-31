<?php

use App\Models\Team;
use App\Services\Pulse\ProductionSummaryBuilder;
use Tests\Pulse\Support\TeamFactory;

function summaryBuilder(): ProductionSummaryBuilder
{
    $teamId = TeamFactory::create('Summary Copy Factory '.uniqid());

    return new ProductionSummaryBuilder(Team::findOrFail($teamId));
}

function attendancePayload(): array
{
    return [
        'staff_count' => 12,
        'hour_count' => 96,
        'total_salary' => 12000,
    ];
}

it('formats growbag shift and total lines with comma-separated EB units and no rupee amounts', function () {
    $lines = summaryBuilder()->build(
        [
            ['shift_name' => 'Day', 'bags_produced' => 1234, 'eb_units' => 1056, 'eb_price' => 10032],
            ['shift_name' => 'Night', 'bags_produced' => 890, 'eb_units' => 720, 'eb_price' => 6840],
        ],
        ['attendance' => attendancePayload()],
        'growbags',
    );

    $productionLines = array_values(array_filter(
        $lines,
        fn (string $line) => str_contains($line, 'bags')
    ));

    expect($productionLines)->toHaveCount(3)
        ->and($productionLines[0])->toBe('🕘 Day: 1,234 bags • 1,056 EB units')
        ->and($productionLines[1])->toBe('🕘 Night: 890 bags • 720 EB units')
        ->and($productionLines[2])->toBe('🕛 Total: 2,124 bags • 1,776 EB units');

    foreach ($productionLines as $line) {
        expect($line)->not->toContain('₹');
    }
});

it('labels labour as Attendance with the rupee amount on pith, fibre, and growbag summaries', function (string $type, array $main) {
    $lines = summaryBuilder()->build($main, ['attendance' => attendancePayload()], $type);

    $attendanceLine = collect($lines)->first(fn (string $line) => str_contains($line, 'Attendance'));

    expect($attendanceLine)->not->toBeNull()
        ->and($attendanceLine)->toContain('👷 Attendance: 12 staff')
        ->and($attendanceLine)->toContain('96 hrs')
        ->and($attendanceLine)->toContain('₹')
        ->and(implode("\n", $lines))->not->toContain('Salary');
})->with([
    'growbags' => ['growbags', [['shift_name' => 'Day', 'bags_produced' => 40, 'eb_units' => 11, 'eb_price' => 104.5]]],
    'pith' => ['pith', [['shift_name' => 'Day', 'loads_expected' => 120]]],
    'fibre' => ['fibre', [['shift_name' => 'Day', 'bales_produced' => 10, 'bales_weighed' => 10, 'weight' => 1.2]]],
]);
