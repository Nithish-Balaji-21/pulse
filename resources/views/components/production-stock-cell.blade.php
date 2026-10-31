@props([
    'level' => null,
    'dateFormat' => 'd/m/Y',
    'decimals' => 0,
])

@php
    /**
     * $level is the entry built by the list component's stockLevels():
     * ['value' => float, 'date' => string, 'recorded' => bool], or null when the daily
     * ledger has no row for this shift's date.
     *
     * The value is the ledger's closing balance for that day — opening + produced -
     * sold + correction. "Recorded" means someone entered the day's movement; without
     * it the balance only reflects production, so it reads as unconfirmed.
     */
    $format = fn ($value) => \App\Services\NumberFormatterService::formatToIndianFormat($value, false, $decimals);
@endphp

@if ($level === null)
    <span class="text-gray-500">&mdash;</span>
@else
    <div class="flex flex-col items-end leading-tight text-right">
        <span class="tabular-nums {{ $level['recorded'] ? 'font-semibold text-gray-800' : 'text-gray-500' }}">
            {{ $format($level['value']) }}
        </span>
        <span class="caps-label {{ $level['recorded'] ? 'text-[#15803d]' : 'text-amber-600' }}">
            {{ $level['recorded'] ? 'confirmed' : 'no entry' }}
        </span>
    </div>
@endif
