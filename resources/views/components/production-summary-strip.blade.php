@props([
    'estimated' => 0,
    'actual' => 0,
    'estimatedComparable' => 0,
    'shifts' => 0,
    'actualShifts' => 0,
    'unit' => '',
    'showActuals' => true,
    'createUrl' => null,
    'decimals' => 0,
    'coverageUnit' => 'shifts',
])

@php
    $format = fn ($value) => \App\Services\NumberFormatterService::formatToIndianFormat($value, false, $decimals);

    /**
     * Variance is only meaningful across the shifts that actually carry an entered figure,
     * so it compares the actual total against the estimate for those same shifts.
     */
    $variance =
        $showActuals && $actualShifts > 0 && $estimatedComparable > 0
            ? (int) round((($actual - $estimatedComparable) / $estimatedComparable) * 100)
            : null;

    $varianceIsShortfall = $variance !== null && $variance <= -10;
@endphp

<div class="my-2 grid grid-cols-1 sm:grid-cols-3 gap-2.5">
    <!-- Card 1: Estimated -->
    <div class="bg-white p-2.5 rounded-lg border border-gray-200 shadow-xs flex items-center gap-3 hover:border-red-200 transition-all">
        <div class="shrink-0 p-2 rounded-lg bg-red-50 text-[#861e1a]">
            <i class="fa-solid fa-chart-line text-sm"></i>
        </div>
        <div>
            <div class="caps-label">Estimated</div>
            <div class="text-lg font-semibold tabular-nums text-[#861e1a] mt-0 leading-tight">
                {{ $format($estimated) }}
                @if ($unit)
                    <span class="text-xs font-normal text-gray-500 ms-0.5">{{ $unit }}</span>
                @endif
            </div>
        </div>
    </div>

    <!-- Card 2: Actual Recorded -->
    @if ($showActuals)
        <div class="bg-white p-2.5 rounded-lg border border-gray-200 shadow-xs flex items-center gap-3 hover:border-green-200 transition-all">
            <div class="shrink-0 p-2 rounded-lg bg-green-50 text-green-700">
                <i class="fa-solid fa-circle-check text-sm"></i>
            </div>
            <div>
                <div class="caps-label">Actuals Recorded</div>
                <div class="text-lg font-semibold tabular-nums {{ $actualShifts > 0 ? 'text-green-700' : 'text-gray-500' }} mt-0 leading-tight">
                    {{ $actualShifts > 0 ? $format($actual) : '—' }}
                    @if ($unit && $actualShifts > 0)
                        <span class="text-xs font-normal text-gray-500 ms-0.5">{{ $unit }}</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Card 3: Actuals Coverage -->
        <div class="bg-white p-2.5 rounded-lg border border-gray-200 shadow-xs flex items-center gap-3 hover:border-blue-200 transition-all">
            <div class="shrink-0 p-2 rounded-lg bg-blue-50 text-blue-700">
                <i class="fa-solid fa-calendar-check text-sm"></i>
            </div>
            <div class="grow">
                <div class="caps-label">Actuals Coverage</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900 mt-0 leading-tight flex items-center gap-2">
                    <span>
                        {{ $format($actualShifts) }}
                        <span class="text-xs font-normal text-gray-500">of {{ $format($shifts) }} {{ $coverageUnit }}</span>
                    </span>
                    @if ($variance !== null)
                        <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full {{ $varianceIsShortfall ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-emerald-100 text-emerald-800 border border-emerald-200' }}">
                            {{ $variance > 0 ? '+' : '' }}{{ $variance }}%
                        </span>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
