<?php

namespace App\Console\Traits;

use Carbon\Carbon;
use Carbon\CarbonPeriod;

trait HasDateRangeOption
{
    /**
     * Process the start and end date options or default to yesterday.
     */
    public function getDatesToProcess(array $dates = []): array
    {
        // Dates from input, if given
        $dates = $this->option('dates') ?: $dates;

        // If the input is a comma-separated string, split it into an array
        if (is_string($dates)) {
            $dates = explode(',', $dates);
        }

        if (! empty($dates)) {
            $dates = array_filter(array_map('trim', $dates));
        }

        // If no dates are provided, use the start and end options
        $start = $this->option('start');
        $end = $this->option('end');

        if (empty($dates) && ! empty($start)) {
            try {
                $startDate = Carbon::createFromFormat('Y-m-d', $start)->startOfDay();
                $endDate = empty($end)
                    ? Carbon::yesterday()->startOfDay()
                    : Carbon::createFromFormat('Y-m-d', $end)->startOfDay();

                // Ensure start date is before end date
                if ($startDate->gt($endDate)) {
                    $this->error('Invalid dates, end date is less than start date.');

                    return [];
                }

                // Generate the date range
                $dates = collect(CarbonPeriod::create($startDate, $endDate))
                    ->map(fn ($date) => $date->format('Y-m-d'))
                    ->toArray();
            } catch (\Exception $e) {
                $this->error('Invalid date format. Use YYYY-MM-DD for --start and --end.');

                return [];
            }
        }

        // Default to yesterday if no dates are provided
        if (empty($dates)) {
            $dates = [Carbon::yesterday()->format('Y-m-d')];
        }

        return $dates;
    }
}
