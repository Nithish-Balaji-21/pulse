@props([
    'label',
    'valueId',
    'icon' => null,
])

{{--
    Dashboard KPI tile, two lines tall: icon and label share the first line, the headline figure
    has the second, and the default slot runs down the right for the per-shift breakdown.

    Pulling the icon up beside the label is what buys the second line back — as its own row it made
    every tile three lines tall for no extra information.

    The value starts as an em dash, never `0` — the figure only arrives with the AJAX response,
    and a hardcoded zero would report "no production" while the request is still in flight.
--}}
<div {{ $attributes->merge(['class' => 'summary-card-item bg-white shadow-xs hover:shadow-md transition-shadow duration-200 px-3 py-2.5 flex justify-between gap-2 border-2 border-gray-300 rounded-xl']) }}>
    <div class="flex flex-col min-w-0 grow justify-center">
        <div class="caps-label leading-tight truncate">{{ $label }}</div>

        {{-- The icon rides the figure's line rather than the label's: it sits beside the number it
             describes, and it leaves the label the full width instead of competing for it. --}}
        <div class="flex items-center gap-1.5 min-w-0 mt-1">
            @if ($icon)
                <img src="{{ asset('images/' . $icon) }}" alt="" class="w-4 h-4 object-contain shrink-0">
            @endif
            <span id="{{ $valueId }}" class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 leading-tight truncate">&mdash;</span>
        </div>
    </div>

    {{ $slot }}
</div>
