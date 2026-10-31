<?php

namespace App\Services\Pulse;

use App\Models\Team;
use App\Services\NumberFormatterService;

class ProductionSummaryBuilder
{
    protected Team $team;

    public function __construct(Team $team)
    {
        $this->team = $team;
    }

    public function build(array $mainModuleSummary, array $otherModulesSummary, string $type = 'pith'): array
    {
        $lines = [];

        if ($type === 'pith') {
            $lines = array_merge($lines, $this->buildPithLoadsSummary($mainModuleSummary));
        } elseif ($type === 'growbags') {
            $lines = array_merge($lines, $this->buildGrowBagsSummary($mainModuleSummary));
        } else {
            $lines = array_merge($lines, $this->buildFibreMegaBalesSummary($mainModuleSummary));
        }

        $lines = array_merge($lines, $this->buildOtherModuleLines($otherModulesSummary));

        return $lines;
    }

    private function buildPithLoadsSummary(array $summary): array
    {
        $lines = [];
        $totalLoads = 0;

        foreach ($summary as $row) {
            $shift = $row['shift_name'];
            $loads = (float) ($row['loads_expected'] ?? $row['cft_expected'] ?? 0);

            if ($loads <= 0) {
                continue;
            }

            $totalLoads += $loads;
            $formattedLoads = NumberFormatterService::formatCompactNumber($loads);
            $lines[] = sprintf('🕘 %s: %s cft', $shift, $formattedLoads);
        }

        if (count($summary) > 1) {
            $formattedTotal = NumberFormatterService::formatCompactNumber($totalLoads);
            $lines[] = sprintf('🕛 Total: %s cft', $formattedTotal);
        }

        return $lines;
    }

    private function buildFibreMegaBalesSummary(array $summary): array
    {
        $lines = [];
        $totalProduced = 0;
        $totalWeighed = 0;
        $totalWeight = 0;

        // First pass: check deviation condition
        $allWithin5Percent = true;
        foreach ($summary as $row) {
            $produced = (int) $row['bales_produced'];
            $weighed = (int) $row['bales_weighed'];
            if ($produced > 0 && $weighed > 0) {
                $deviation = abs($weighed - $produced) / $produced;
                if ($deviation > 0.05) {
                    $allWithin5Percent = false;
                    break;
                }
            }
        }

        // Second pass: build lines
        foreach ($summary as $row) {
            $shift = $row['shift_name'];
            $produced = (int) $row['bales_produced'];
            $weighed = (int) $row['bales_weighed'];
            $weight = (float) $row['weight'];

            $totalProduced += $produced;
            $totalWeighed += $weighed;
            $totalWeight += $weight;

            $producedLabel = NumberFormatterService::formatToIndianFormat($produced, true);
            $weighedLabel = NumberFormatterService::formatToIndianFormat($weighed, true);

            $avgKg = $weighed > 0 ? ($weight * 1000) / $weighed : 0;
            // Conditional weight formatting
            $formattedWeight = $weight >= 10 ? number_format($weight, 0) : number_format($weight, 1);
            if ($weighed > 0 && ! $allWithin5Percent) {
                // show weighed + weight details
                $lines[] = sprintf(
                    '🕘 %s: %s bales • %s T (%s bales, %.0f kg)',
                    $shift, $producedLabel, $formattedWeight, $weighedLabel, $avgKg
                );
            } else {
                // show only produced
                $lines[] = sprintf('🕘 %s: %s bales • %s T (%.0f kg)', $shift, $producedLabel, $formattedWeight, $avgKg);
            }
        }

        // Total line
        if (count($summary) > 1) {
            $totalProducedLabel = NumberFormatterService::formatToIndianFormat($totalProduced, true);
            $totalWeighedLabel = NumberFormatterService::formatToIndianFormat($totalWeighed, true);

            $totalAvgKg = $totalWeighed > 0 ? ($totalWeight * 1000) / $totalWeighed : 0;
            // Conditional total weight formatting
            $formattedTotalWeight = $totalWeight >= 10 ? number_format($totalWeight, 0) : number_format($totalWeight, 1);

            if ($totalWeighed > 0 && ! $allWithin5Percent) {
                $lines[] = sprintf(
                    '🕛 Total: %s bales • %s T (%s bales, %.0f kg)',
                    $totalProducedLabel, $formattedTotalWeight, $totalWeighedLabel, $totalAvgKg
                );
            } else {
                $lines[] = sprintf('🕛 Total: %s bales • %s T (%.0f kg)', $totalProducedLabel, $formattedTotalWeight, $totalAvgKg);
            }
        }

        return $lines;
    }

    private function buildOtherModuleLines(array $data): array
    {
        $lines = [];

        if (! empty($data['pith_blocks'])) {
            $formattedBlocks = NumberFormatterService::formatToIndianFormat($data['pith_blocks']);
            $lines[] = "🟫 Pith Blocks: {$formattedBlocks}";
        }

        if (! empty($data['fibre_bales'])) {
            $formattedBales = NumberFormatterService::formatToIndianFormat($data['fibre_bales']);
            $lines[] = "📦 Fibre Bales: {$formattedBales}";
        }

        if (! empty($data['water'])) {
            $formattedWater = NumberFormatterService::formatToIndianFormat($data['water'], true);
            $lines[] = "💧 Water Consumed: {$formattedWater} ltrs";
        }

        $hasProductionData = ! empty($data['pith_blocks']) || ! empty($data['fibre_bales']);

        if ($hasProductionData || ! empty($data['attendance']['staff_count'])) {
            $lines[] = $this->formatAttendance($data['attendance']);
        }

        if (! empty($data['pith_stock'])) {
            $formattedStock = NumberFormatterService::formatCompactNumber($data['pith_stock']);
            $lines[] = "📦 Pith Stock: {$formattedStock} cft";
        }

        if (! empty($data['husks_stock'])) {
            $formattedHusksStock = NumberFormatterService::formatCompactNumber($data['husks_stock']);
            $lines[] = "📦 Husks Stock: {$formattedHusksStock} cft";
        }

        return $lines;
    }

    private function buildGrowBagsSummary(array $summary): array
    {
        $lines = [];
        $totalBags = 0;
        $totalEbUnits = 0;

        foreach ($summary as $row) {
            $shift = $row['shift_name'];
            $bags = (int) $row['bags_produced'];
            $ebUnits = (int) $row['eb_units'];

            $totalBags += $bags;
            $totalEbUnits += $ebUnits;

            $formattedBags = NumberFormatterService::formatToIndianFormat($bags, true);
            $formattedEbUnits = NumberFormatterService::formatToIndianFormat($ebUnits, true);

            $lines[] = sprintf(
                '🕘 %s: %s bags • %s EB units',
                $shift, $formattedBags, $formattedEbUnits
            );
        }

        if (count($summary) > 1) {
            $formattedTotalBags = NumberFormatterService::formatToIndianFormat($totalBags, true);
            $formattedTotalEbUnits = NumberFormatterService::formatToIndianFormat($totalEbUnits, true);

            $lines[] = sprintf(
                '🕛 Total: %s bags • %s EB units',
                $formattedTotalBags, $formattedTotalEbUnits
            );
        }

        return $lines;
    }

    private function formatAttendance(array $attendance): string
    {
        $line = '';
        $details = [];

        $details[] = "👷 Attendance: {$attendance['staff_count']} staff";

        if (! empty($attendance['hour_count'])) {
            $formattedHours = $attendance['hour_count'] >= 100
                ? (int) round($attendance['hour_count'])
                : round($attendance['hour_count'], 1);
            $details[] = "{$formattedHours} hrs";
        }

        if (! empty($attendance['total_salary'])) {
            $formattedSalary = NumberFormatterService::formatToIndianCurrency($attendance['total_salary'], 0, true);
            $details[] = $formattedSalary;
        }

        if (! empty($details)) {
            $line .= implode(' • ', $details);
        }

        return $line;
    }
}
