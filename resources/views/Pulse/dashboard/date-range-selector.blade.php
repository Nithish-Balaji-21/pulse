{{--
    Dashboard period selector.

    A single bar: the resolved range on the left, quick presets on the right.
    "Custom" (and clicking the range itself) opens the daterangepicker anchored
    on #reportrange, so the presets and the calendar stay one control.

    @param array  $presets  preset key => label. Keys must exist in rangePresets
                            (daterangeselector.js).
    @param string $default  preset lit — and fetched — on load.
    @param string $dayType  bucketing sent when the selection is a single day,
                            however it was chosen. 'day' on the dashboards with
                            an hourly view; 'range' where one day is just a
                            one-day range.
    @param string $accent   colour of the active pill and the calendar glyph.

    Behaviour lives in public/js/daterangeselector.js (initRangeSelector); the
    active-pill and track styling lives in public/css/date-range-selector.css,
    because the active state is toggled from JS and would otherwise be invisible
    to the Tailwind scanner.
--}}
@php
    $presets ??= [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => '7D',
        '1m' => '1M',
        'ytd' => 'YTD',
        'custom' => 'Custom',
    ];
    $default ??= 'yesterday';
    $dayType ??= 'range';
    $accent ??= config('colors.pith.total');
@endphp

<div class="pulse-range-bar"
    style="--pulse-range-active: {{ $accent }};"
    data-range-default="{{ $default }}"
    data-range-day-type="{{ $dayType }}">
    <button type="button" id="reportrange" class="pulse-range-trigger" aria-label="Change date range">
        <span class="pulse-range-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="2.6" y="4.6" width="18.8" height="16.8" rx="5" stroke="currentColor" stroke-width="2.2"/>
                <path d="M3 9.6h18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </span>
        <span id="selected-range" class="pulse-range-label"></span>
    </button>

    <div class="pulse-range-presets" role="group" aria-label="Quick date ranges">
        @foreach ($presets as $preset => $label)
            <button
                type="button"
                class="pulse-range-preset"
                data-range-preset="{{ $preset }}"
                aria-pressed="false">{{ $label }}</button>
        @endforeach
    </div>
</div>
