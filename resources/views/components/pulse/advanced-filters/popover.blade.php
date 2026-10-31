@props([
    'categories' => [],
    'activeCategory' => 'date',
    'matchCount' => 0,
    'totalCount' => 0,
    'categoryDots' => [],
])

{{--
  Expects parent Alpine/Livewire wrap with filtersOpen.
  Slot: category panel content (sections toggled by activeCategory).
--}}
<div
    class="absolute right-0 top-[calc(100%+10px)] z-50 w-[640px] max-w-[92vw] overflow-hidden rounded-[14px] border border-[#E9E3D4] bg-white shadow-[0_12px_32px_rgba(36,31,24,0.14),0_3px_8px_rgba(36,31,24,0.08)] max-lg:right-[-40px]"
    wire:click.stop
    @click.stop
>
    {{-- caret --}}
    <div class="pointer-events-none absolute -top-[7px] right-[26px] h-3.5 w-3.5 rotate-45 border-l border-t border-[#E9E3D4] bg-white"></div>

    <div class="flex items-center justify-between border-b border-[#E9E3D4] px-[18px] py-3.5">
        <h3 class="m-0 text-sm font-semibold text-gray-900">Advanced search</h3>
        <button
            type="button"
            wire:click="closeFilters"
            class="flex h-[26px] w-[26px] items-center justify-center rounded-[7px] text-gray-400 hover:bg-[#F7F4EC] hover:text-gray-800"
            aria-label="Close filters"
        >
            <svg class="h-[15px] w-[15px] stroke-current" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="flex min-h-[300px] max-h-[420px] max-lg:flex-col">
        <div class="w-[172px] shrink-0 overflow-y-auto border-r border-[#E9E3D4] bg-[#FCFAF4] px-2 py-3 max-lg:flex max-lg:w-full max-lg:shrink-0 max-lg:gap-1 max-lg:overflow-x-auto max-lg:border-b max-lg:border-r-0 max-lg:whitespace-nowrap">
            @foreach ($categories as $key => $label)
                <button
                    type="button"
                    wire:click="setFilterCategory('{{ $key }}')"
                    @class([
                        'mb-0.5 flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-[13px] max-lg:mb-0 max-lg:inline-flex max-lg:w-auto max-lg:shrink-0',
                        'bg-[#FBE9DD]/80 font-semibold text-[#861e1a]' => $activeCategory === $key,
                        'text-gray-600 hover:bg-[#E9E3D4] hover:text-gray-900' => $activeCategory !== $key,
                    ])
                >
                    <span>{{ $label }}</span>
                    <span
                        @class([
                            'ml-2 h-1.5 w-1.5 shrink-0 rounded-full bg-[#861e1a]',
                            'opacity-100' => ! empty($categoryDots[$key]),
                            'opacity-0' => empty($categoryDots[$key]),
                        ])
                    ></span>
                </button>
            @endforeach
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-[18px]">
            {{ $slot }}
        </div>
    </div>

    <div class="flex items-center justify-between border-t border-[#E9E3D4] bg-[#FCFAF4] px-[18px] py-3">
        <span class="text-[13px] text-gray-600">
            <b class="font-semibold tabular-nums text-gray-900">{{ number_format($matchCount) }}</b>
            of {{ number_format($totalCount) }} records match
        </span>
        <div class="flex gap-2">
            <button
                type="button"
                wire:click="clearAllFilters"
                class="rounded-lg border border-gray-400 bg-transparent px-3.5 py-2 text-[13px] font-medium text-gray-600 hover:border-[#8B2E2E] hover:text-[#8B2E2E]"
            >
                Clear all
            </button>
            <button
                type="button"
                wire:click="applyFilters"
                class="rounded-lg border border-transparent bg-[#861e1a] px-3.5 py-2 text-[13px] font-medium text-white hover:bg-[#6a1a15]"
            >
                Apply filters
            </button>
        </div>
    </div>
</div>
