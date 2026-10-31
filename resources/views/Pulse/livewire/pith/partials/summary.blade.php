{{-- Flash Message for Production record saved successfully! --}}
<div class="relative">
    @if (session('message'))
        <div id="flash-message"
            class="z-50 bg-[#e2faf2] border-2 mt-4 border-white text-gray-600 px-4 py-3 rounded-xl fixed top-0 left-1/2 transform -translate-x-1/2 shadow-md animate-slide-down opacity-100 transition-opacity duration-500 ease-in-out ring-2 ring-gray-50"
            role="alert">
            <i class="p-2 text-white bg-green-600 rounded-lg fa-solid fa-circle-check"></i>
            <span class="ml-1 font-semibold">{{ session('message') }}</span>
        </div>
    @endif
</div>

<!-- table content -->
<div class="min-h-20">
    <table class="w-full border-t border-b table-fixed">
        <thead class="sticky top-[50px] z-10">
            <tr class="text-xs border-b border-gray-300 table-header">
                <th class="w-[8%] py-3 pe-1 font-semibold text-center ps-4">
                    <a class="text-nowrap" wire:click.prevent="sortBy('shift_date')" href="#">
                        DATE
                        @if ($sortColumn == 'shift_date')
                            @if ($sortDirection == 'asc')
                                <i class="text-gray-600 fa fa-caret-up fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa fa-caret-down fa-sm"></i>
                            @endif
                        @else
                            <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                        @endif
                    </a>
                </th>
                <th class="w-[6%] py-3 pe-1 font-semibold text-center">
                    SHIFT
                </th>
                <th class="w-[20%] py-3 pe-1 font-semibold text-center">
                    <a class="text-nowrap" wire:click.prevent="sortBy('start_time')" href="#">
                        TIME
                        @if ($sortColumn == 'start_time')
                            @if ($sortDirection == 'asc')
                                <i class="text-gray-600 fa fa-caret-up fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa fa-caret-down fa-sm"></i>
                            @endif
                        @else
                            <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                        @endif
                    </a>
                </th>
                <th class="w-[10%] py-3 pe-1 font-semibold text-center">
                    STAFF
                </th>
                <th class="w-[14%] py-3 pe-4 font-semibold text-right">
                    <a class="text-nowrap" wire:click.prevent="sortBy('cft_produced')" href="#">
                        OUTPUT (CFT)
                        @if ($sortColumn == 'cft_produced')
                            @if ($sortDirection == 'asc')
                                <i class="text-gray-600 fa fa-caret-up fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa fa-caret-down fa-sm"></i>
                            @endif
                        @else
                            <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                        @endif
                    </a>
                </th>
                <th class="w-[11%] py-3 pe-4 font-semibold text-right">
                    INVENTORY (CFT)
                </th>
                <th class="w-[9%] py-3 pe-4 font-semibold text-right">
                    PROD. HOURS
                </th>
                <th class="w-[9%] py-3 pe-4 font-semibold text-right text-nowrap">
                    EB PER CFT
                </th>
                <th class="w-[12%] py-3 pe-1 font-semibold text-center">
                    <a class="text-nowrap" wire:click.prevent="sortBy('updated_at')" href="#">
                        UPDATED ON
                        @if ($sortColumn == 'pulse_summary_pith_productions.updated_at')
                            @if ($sortDirection == 'asc')
                                <i class="text-gray-600 fa fa-caret-up fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa fa-caret-down fa-sm"></i>
                            @endif
                        @else
                            <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                        @endif
                    </a>
                </th>
                <th class="w-[3%] py-3 pe-3 font-semibold text-center"></th>
            </tr>
        </thead>
        <tbody class="text-gray-900 tabular-nums">
            @php
                $rowPy = 'py-2';
                // The output bar compares rows against the heaviest shift on the page, so
                // it stays populated from the estimate when no actual was entered.
                $barScale = $productions
                    ->map(fn ($row) => max((float) $row->loads_expected, (float) $row->loads_produced))
                    ->max() ?? 0;
            @endphp
            @foreach ($productions as $production)
                <tr class="cursor-pointer border-b border-gray-300 hover:bg-[#f6f6fa]" wire:click="selectProduction({{ $production->id }})">
                    <td class="{{ $rowPy }} text-sm text-nowrap ps-4">
                        {{ \Carbon\Carbon::parse($production->shift_date)->format($dateFormat) }}
                    </td>
                    <td class="{{ $rowPy }} text-sm text-nowrap text-center">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                            {{ $production->shift ? $production->shift_name : '-' }}
                        </span>
                    </td>
                    <td class="{{ $rowPy }} text-sm text-nowrap text-center">
                        {{ \Carbon\Carbon::parse($production->start_time)->format('g:i a') }}
                        &ndash;
                        {{ \Carbon\Carbon::parse($production->end_time)->roundMinute()->format('g:i a') }}
                    </td>
                    <td class="{{ $rowPy }} text-sm text-nowrap text-left">
                        {{ $production->staff ? $production->staff->staff_name : '-' }}
                    </td>
                    <td class="{{ $rowPy }} text-sm pe-4">
                        <x-production-output-cell
                            :expected="$production->loads_expected"
                            :actual="$production->loads_produced"
                            :scale="$barScale"
                            :showActuals="$showActuals" />
                    </td>
                    <td class="{{ $rowPy }} text-sm text-right pe-4">
                        {{ \App\Services\NumberFormatterService::formatCompactNumber($production->total_pith_loads) }}
                    </td>
                    <td class="{{ $rowPy }} text-sm text-right pe-4">
                        {{ $production->load_hrs ? number_format($production->load_hrs, 2) : '-' }}
                    </td>
                    <td class="{{ $rowPy }} text-sm text-right text-nowrap pe-4">
                        {{ $production->eb_price > 0 && $production->loads_produced > 0 ? '₹' . number_format($production->eb_price / $production->loads_produced, 2) : '-' }}
                    </td>
                    <td class="{{ $rowPy }} text-sm text-center">
                        {{ $production->updated_at ? \Carbon\Carbon::parse($production->updated_at)->format($dateFormat . ' g:i a') : '-' }}
                    </td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Pagination Controls -->
    <x-pagination :items="$productions" perPageBinding="perPage" />
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const flashMessage = document.getElementById('flash-message');
        if (flashMessage) {
            // Wait for 3 seconds, then fade out smoothly
            setTimeout(() => {
                flashMessage.classList.add('opacity-0', 'translate-y-[-20px]');
                setTimeout(() => flashMessage.remove(), 500); // Remove from DOM after transition
            }, 3000);
        }
    });
</script>
