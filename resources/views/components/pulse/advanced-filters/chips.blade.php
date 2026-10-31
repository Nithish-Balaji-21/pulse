@props([
    'chips' => [],
    'logic' => 'and',
])

@if (count($chips) > 0)
    <div class="mb-[18px] mt-[-6px] flex flex-wrap items-center gap-2 rounded-[10px] border border-dashed border-[#DCD4C0] bg-[#FDFBF6] px-3 py-2.5">
        <span class="mr-0.5 caps-label">Filtered by</span>

        <div class="mr-1 inline-flex rounded-full bg-[#E9E3D4] p-0.5 text-xs font-semibold">
            <button
                type="button"
                wire:click="setFilterLogic('and')"
                @class([
                    'rounded-[18px] border-0 px-2.5 py-1 cursor-pointer',
                    'bg-white text-[#861e1a] shadow-sm' => $logic === 'and',
                    'bg-transparent text-gray-600' => $logic !== 'and',
                ])
            >
                Match all
            </button>
            <button
                type="button"
                wire:click="setFilterLogic('or')"
                @class([
                    'rounded-[18px] border-0 px-2.5 py-1 cursor-pointer',
                    'bg-white text-[#861e1a] shadow-sm' => $logic === 'or',
                    'bg-transparent text-gray-600' => $logic !== 'or',
                ])
            >
                Match any
            </button>
        </div>

        @foreach ($chips as $chip)
            <div class="flex items-center gap-1.5 rounded-full border border-[#F0C7A8] bg-[#FBE9DD] py-1 pl-3 pr-1 text-[13px] font-medium text-[#861e1a]">
                <span>
                    <span class="font-normal text-gray-600">{{ $chip['label'] }}:</span>
                    {{ $chip['value'] }}
                </span>
                <button
                    type="button"
                    wire:click="removeFilterChip('{{ $chip['key'] }}')"
                    class="flex h-[17px] w-[17px] shrink-0 items-center justify-center rounded-full border-0 bg-[rgba(134,30,26,0.12)] p-0"
                    aria-label="Remove {{ $chip['label'] }} filter"
                >
                    <svg class="h-[9px] w-[9px] stroke-[#861e1a]" viewBox="0 0 24 24" fill="none" stroke-width="3" stroke-linecap="round">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        @endforeach

        <button
            type="button"
            wire:click="clearAllFilters"
            class="ml-0.5 cursor-pointer text-xs text-gray-600 underline hover:text-[#8B2E2E]"
        >
            Clear all
        </button>
    </div>
@endif
