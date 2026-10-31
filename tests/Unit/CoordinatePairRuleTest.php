<?php

use App\Rules\CoordinatePair;
use Illuminate\Support\Facades\Validator;

/**
 * Validates a single `coordinates` value against the rule as the forms apply it.
 */
function validateCoordinates(?string $value): Illuminate\Validation\Validator
{
    return Validator::make(
        ['coordinates' => $value],
        ['coordinates' => ['nullable', 'string', 'max:60', new CoordinatePair]],
    );
}

it('passes a well formed pair', function (string $raw) {
    expect(validateCoordinates($raw)->passes())->toBeTrue();
})->with([
    '10.8128168,77.1014343',
    ' 10.793353, 77.098192 ',
    '-33.86,-151.20',
    '10.812816799999999, 77.10143430000001',
]);

it('passes when the field is left empty', function (?string $raw) {
    expect(validateCoordinates($raw)->passes())->toBeTrue();
})->with([null, '']);

it('fails a malformed or out of range pair', function (string $raw) {
    expect(validateCoordinates($raw)->passes())->toBeFalse();
})->with(['10.77', 'Coimbatore', '10.77;77.08', '10.77,77.08,5', '91.5,77.08', '10.77,-180.5']);

it('fails a value longer than the column-backed maximum', function () {
    expect(validateCoordinates(str_repeat('1', 30).'.5,'.str_repeat('7', 30))->passes())->toBeFalse();
});
