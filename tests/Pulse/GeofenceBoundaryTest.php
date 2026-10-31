<?php

use App\Support\Pulse\GeofenceBoundary;

it('normalizes a circle boundary', function () {
    $result = GeofenceBoundary::normalize([
        'boundary_type' => 'circle',
        'latitude' => 11.01,
        'longitude' => 76.96,
        'radius' => 80,
    ]);

    expect($result['boundary_type'])->toBe('circle')
        ->and($result['radius'])->toBe(80)
        ->and($result['polygon_coordinates'])->toBeNull();
});

it('computes a polygon centroid and rejects self-intersection', function () {
    $result = GeofenceBoundary::normalize([
        'boundary_type' => 'polygon',
        'polygon_coordinates' => [
            ['lat' => 11.0, 'lng' => 76.0],
            ['lat' => 11.0, 'lng' => 76.2],
            ['lat' => 11.2, 'lng' => 76.2],
            ['lat' => 11.2, 'lng' => 76.0],
        ],
    ]);

    expect($result['boundary_type'])->toBe('polygon')
        ->and($result['latitude'])->toEqualWithDelta(11.1, 0.0001)
        ->and($result['longitude'])->toEqualWithDelta(76.1, 0.0001)
        ->and($result['polygon_coordinates'])->toHaveCount(4);

    expect(fn () => GeofenceBoundary::normalize([
        'boundary_type' => 'polygon',
        'polygon_coordinates' => [
            ['lat' => 11.00, 'lng' => 76.00],
            ['lat' => 11.02, 'lng' => 76.02],
            ['lat' => 11.00, 'lng' => 76.02],
            ['lat' => 11.02, 'lng' => 76.00],
        ],
    ]))->toThrow(InvalidArgumentException::class);
});
