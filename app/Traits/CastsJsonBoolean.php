<?php

namespace App\Traits;

trait CastsJsonBoolean
{
    protected function castJsonBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (bool) $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
