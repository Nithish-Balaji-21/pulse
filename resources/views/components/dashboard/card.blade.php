@props([
    'title',
    'titleId' => null,
    'emptyId' => null,
    'emptyMessage' => 'No data available for the selected duration.',
])

{{--
    The dashboard chart-card shell. Extracted verbatim from the shape every partial under
    Pulse/dashboard/charts/ had copy-pasted (see waterconsumption.blade.php, devicestates.blade.php)
    so the fibre dashboard picks up the same chrome the pith dashboard already has.

    `$meta` is the right-aligned inline legend/summary slot those partials render beside the title.
--}}
<div {{ $attributes->merge(['class' => 'h-full bg-white rounded shadow-[0_0_10px_rgba(0,0,0,0.15)] px-4 pt-4']) }}>
    {{--
        The heading pins itself under the page header, which is sticky on these dashboards.
        Without it a scrolled card lost its title behind that header and the chart stood
        unlabelled. `--pulse-header-bottom` is published by the layout; the fallback of 0 keeps
        the heading in place on any page that does not set it.

        The negative margins let the pinned band span the card's full width and reach its top
        edge, so content scrolling underneath cannot show through the card's own padding.
    --}}
    <div class="sticky z-20 bg-white -mx-4 -mt-4 px-4 pt-4" style="top: var(--pulse-header-bottom, 0px);">
        <div class="flex flex-wrap justify-between items-start gap-2 mb-2">
            <h3 @if ($titleId) id="{{ $titleId }}" @endif class="text-lg font-semibold text-gray-800">{{ $title }}</h3>

            @isset($meta)
                <div class="flex items-center gap-4 text-xs font-medium text-gray-700">{{ $meta }}</div>
            @endisset
        </div>

        <hr class="border-t border-gray-400 my-2">
    </div>

    {{ $slot }}

    @if ($emptyId)
        <p id="{{ $emptyId }}" class="hidden text-sm text-gray-700 mt-2 pb-4">{{ $emptyMessage }}</p>
    @endif
</div>
