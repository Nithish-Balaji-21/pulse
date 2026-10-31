<?php

namespace App\Services;

class NumberFormatterService
{
    public static function formatToIndianCurrency($amount, $decimals, $compactFormat = false)
    {
        if (! is_numeric($amount)) {
            return '₹0';
        }

        if ($compactFormat) {
            return '₹'.self::formatCompactNumber($amount);
        }

        $formatter = new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return $formatter->formatCurrency($amount, 'INR');
    }

    public static function formatToIndianFormat($number, $compactFormat = false, $decimals = 2)
    {
        if ($compactFormat) {
            return self::formatCompactNumber($number);
        }

        $formatter = new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return $formatter->format($number);
    }

    public static function formatCompactNumber($number)
    {
        $absNum = abs($number);
        $sign = $number < 0 ? '-' : '';

        if ($absNum >= 10000000) {
            $crValue = round($absNum / 10000000, 2);
            $formatted = number_format($crValue, 2, '.', '');
            $formatted = rtrim(rtrim($formatted, '0'), '.');

            return $sign.$formatted.'C';
        } elseif ($absNum >= 100000) {
            $lValue = round($absNum / 100000, 2);
            $formatted = number_format($lValue, 2, '.', '');
            $formatted = rtrim(rtrim($formatted, '0'), '.');

            return $sign.$formatted.'L';
        } elseif ($absNum >= 10000) {
            $kValue = round($absNum / 1000, 1);
            $formatted = number_format($kValue, 1, '.', '');
            $formatted = rtrim(rtrim($formatted, '0'), '.');

            return $sign.$formatted.'K';
        }

        return $sign.number_format(round($absNum), 0, '.', ',');
    }

    public function formatToFriendlyDuration($seconds)
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        // Calculate total minutes from seconds
        $minutes = round($seconds / 60);

        // Calculate hours and adjust minutes according to the rules
        $hours = floor($minutes / 60);
        $minutes = $minutes % 60;

        // Apply minutes rules
        if ($minutes == 59) {
            $hours += 1;
            $minutes = 0;
        }

        // Format the output based on hours and minutes, omitting minutes if they are 0
        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$minutes}m";
        }
    }
}
