<?php

use App\Support\Pulse\ProductionEntryPolicyKeys;

test('production entry policy keys define ten day defaults for all modules', function () {
    expect(ProductionEntryPolicyKeys::DEFINITIONS[ProductionEntryPolicyKeys::BLOCKS]['default_days'])->toBe(10)
        ->and(ProductionEntryPolicyKeys::DEFINITIONS[ProductionEntryPolicyKeys::LOADS]['default_days'])->toBe(10)
        ->and(ProductionEntryPolicyKeys::DEFINITIONS[ProductionEntryPolicyKeys::BALES]['default_days'])->toBe(10);
});

test('production entry policy day options use the fixed customization values', function () {
    expect(ProductionEntryPolicyKeys::dayOptionsList())->toHaveCount(10)
        ->and(ProductionEntryPolicyKeys::dayOptionsList()[0])->toBe('1')
        ->and(ProductionEntryPolicyKeys::dayOptionsList()[3])->toBe('5')
        ->and(ProductionEntryPolicyKeys::dayOptionsList()[9])->toBe('30');
});

test('production entry policy rows seed blocks loads and optional bales', function () {
    $now = now();
    $rows = ProductionEntryPolicyKeys::rowsForTeam(99, true, true, $now);

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('key')->all())->toBe([
            ProductionEntryPolicyKeys::BLOCKS,
            ProductionEntryPolicyKeys::LOADS,
            ProductionEntryPolicyKeys::BALES,
        ])
        ->and($rows[0]['value'])->toBe('10')
        ->and($rows[0]['options'])->toBe('1,2,3,5,7,10,14,21,28,30')
        ->and($rows[0]['value_remark'])->toBeNull();
});

test('production entry policy labels and normalization stay numeric', function () {
    expect(ProductionEntryPolicyKeys::normalizeDay(4))->toBe(3)
        ->and(ProductionEntryPolicyKeys::normalizeDay(6))->toBe(5)
        ->and(ProductionEntryPolicyKeys::normalizeDay(29))->toBe(28)
        ->and(ProductionEntryPolicyKeys::normalizeDay(0))->toBe(10)
        ->and(ProductionEntryPolicyKeys::formatDaysLabel('10'))->toBe('10')
        ->and(ProductionEntryPolicyKeys::DEFINITIONS[ProductionEntryPolicyKeys::LOADS]['text'])
        ->toBe('How many days should Pith Production editing be allowed for?');
});
