<?php

use App\Models\Team;
use App\Models\TeamPulse;

/**
 * Builds an unsaved team carrying the given raw `coordinates` value.
 */
function teamWithCoordinates(?string $coordinates): Team
{
    $team = new Team;
    $team->setRelation('pulse', new TeamPulse(['coordinates' => $coordinates]));

    return $team;
}

it('parses coordinates stored without a space', function () {
    expect(teamWithCoordinates('10.8128168,77.1014343')->coordinatePair())
        ->toBe(['lat' => 10.8128168, 'lng' => 77.1014343]);
});

it('parses coordinates stored with surrounding whitespace', function () {
    expect(teamWithCoordinates(' 10.793353, 77.098192 ')->coordinatePair())
        ->toBe(['lat' => 10.793353, 'lng' => 77.098192]);
});

it('parses negative coordinates', function () {
    expect(teamWithCoordinates('-33.86,-151.20')->coordinatePair())
        ->toBe(['lat' => -33.86, 'lng' => -151.20]);
});

it('returns null when the team has no coordinates', function () {
    expect(teamWithCoordinates(null)->coordinatePair())->toBeNull();
});

it('returns null for malformed coordinates', function (string $raw) {
    expect(teamWithCoordinates($raw)->coordinatePair())->toBeNull();
})->with(['', '10.77', 'Coimbatore', '10.77;77.08', '10.77,77.08,5']);

it('returns null when either value is outside its valid range', function (string $raw) {
    expect(teamWithCoordinates($raw)->coordinatePair())->toBeNull();
})->with(['91.5,77.08', '-90.1,77.08', '10.77,180.5', '10.77,-180.5']);

it('parses full-precision coordinates pasted from a map', function () {
    expect(teamWithCoordinates('10.812816799999999, 77.10143430000001')->coordinatePair())
        ->toBe(['lat' => 10.812816799999999, 'lng' => 77.10143430000001]);
});
