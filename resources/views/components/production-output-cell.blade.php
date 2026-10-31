@props([
    'expected' => null,
    'actual' => null,
    'scale' => 0,
    'showActuals' => true,
    'decimals' => 0,
])

@php
    $expectedValue = $expected !== null ? (float) $expected : null;
    $actualValue = $actual !== null ? (float) $actual : null;

    /**
     * A stored 0 means "nobody recorded a figure", not "the shift produced nothing".
     * bales_produced / blocks_produced default to 0 and are never written as NULL, so the
     * two cases are indistinguishable in the database and 0 is read as not-recorded here.
     */
    $hasActual = $showActuals && $actualValue !== null && $actualValue > 0;
    $hasExpected = $expectedValue !== null && $expectedValue > 0;

    $scaleValue = (float) $scale;
    $barBasis = $hasActual ? $actualValue : $expectedValue;
    $barPercent =
        $scaleValue > 0 && $barBasis > 0 ? min(round(($barBasis / $scaleValue) * 100), 100) : 0;

    $variance =
        $hasActual && $hasExpected
            ? (int) round((($actualValue - $expectedValue) / $expectedValue) * 100)
            : null;

    // Only a real shortfall is worth flagging; overshoot and small misses read as fine.
    $varianceIsShortfall = $variance !== null && $variance <= -10;

    $format = fn ($value) => \App\Services\NumberFormatterService::formatToIndianFormat($value, false, $decimals);
@endphp

<div class="flex flex-col items-end gap-1 ms-auto max-w-56">
    @if ($hasActual)
        <div class="flex items-baseline justify-end gap-1.5 text-nowrap">
            <span class="text-[15px] font-semibold tabular-nums" style="color: #15803d;">{{ $format($actualValue) }}</span>
            @if ($hasExpected)
                <span class="text-[13px] font-medium tabular-nums" style="color: #861e1a;">of {{ $format($expectedValue) }} est</span>
                <span class="text-xs font-semibold tabular-nums px-1.5 py-px min-w-11 text-center rounded {{ $varianceIsShortfall ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' }}">
                    {{ $variance > 0 ? '+' : '' }}{{ $variance }}%
                </span>
            @endif
        </div>
    @elseif ($hasExpected)
        <div class="flex items-baseline justify-end gap-1.5 text-nowrap">
            <span class="text-[15px] font-semibold tabular-nums" style="color: #861e1a;">{{ $format($expectedValue) }}</span>
            <span class="text-xs font-semibold" style="color: #861e1a;">est</span>
        </div>
    @else
        <span class="text-gray-500">&mdash;</span>
    @endif

    @if ($barPercent > 0)
        {{-- Bar compares this shift against the heaviest on the page, so it is populated
             whenever an estimate exists rather than only when an actual was entered. --}}
        <div class="w-full h-1.5 rounded-full bg-gray-200 overflow-hidden">
            <div class="h-full rounded-full"
                 style="width: {{ $barPercent }}%; background-color: {{ $hasActual ? '#15803d' : '#861e1a' }};"></div>
        </div>
    @endif
</div>
