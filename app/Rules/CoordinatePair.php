<?php

namespace App\Rules;

use App\Models\Team;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a factory location string in "latitude,longitude" form.
 *
 * Accepts exactly what `Team::parseCoordinates()` can read back, so a value
 * that passes here is guaranteed to resolve to a usable pair later.
 */
class CoordinatePair implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || Team::parseCoordinates($value) === null) {
            $fail('The :attribute must be a latitude and longitude separated by a comma, e.g. 11.33233, 77.12345.');
        }
    }
}
