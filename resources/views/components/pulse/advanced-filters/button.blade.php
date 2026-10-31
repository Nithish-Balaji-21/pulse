@props([
    'count' => 0,
    'open' => false,
])

<button
    type="button"
    wire:click="toggleFilters"
    @class([
        'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border bg-white px-3 py-2 text-[13px] font-medium transition-colors',
        'border-[#861e1a] bg-[#fbe9dd]/40 text-[#861e1a]' => $open || $count > 0,
        'border-gray-400 text-gray-800 hover:border-[#861e1a] hover:text-[#861e1a]' => ! $open && $count === 0,
    ])
>
    <svg class="h-[15px] w-[15px] stroke-current" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 6h16M7 12h10M10 18h4"/>
    </svg>
    Filters
    @if ($count > 0)
        <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-[#861e1a] px-1.5 text-xs font-semibold tabular-nums text-white">
            {{ $count }}
        </span>
    @endif
</button>
