<div class="w-full" x-data="{ showHiddenDiv: false }">
    <x-slot name="header">
        <div class="flex place-content-between items-center">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                    <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                        <x-menu-icon name="growbags" :size="28" class="text-white" />
                    </div>
                </div>
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Growbag Production') }}</h2>
                    <p class="text-sm text-gray-500 mt-0.5">Shift-wise growbag production, by machine</p>
                </div>
            </div>
            <div class="flex items-center gap-1">
                <div class="relative w-64">
                    <input type="text" wire:model.live.debounce.500ms="search"
                        class="w-full form-input border border-1 rounded-lg text-sm ps-9 pe-2 py-1.5 border-gray-400 hover:border-[#861e1a] focus:border-[#861e1a] focus:ring-0 focus:ring-[#861e1a] focus:shadow-[0_0_2px_#861e1a]"
                        placeholder="Search..." />
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                </div>
            </div>
        </div>
    </x-slot>

    {{-- Machine pill filter tabs --}}
    @if (count($machines) >= 1)
        @php
            $machinePalette = config('colors.growbags_charts.machines', []);
        @endphp
        <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-4 mt-4">
            <div class="flex items-center gap-2 mb-2">
                <i class="fa-solid fa-cogs text-gray-600"></i>
                <span class="text-sm font-semibold text-gray-700">Filter by Machine:</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <button
                    wire:click="filterByMachine(null)"
                    class="text-xs font-semibold px-3 py-1.5 rounded-full border transition-all duration-150"
                    style="{{ is_null($filterMachineId)
                        ? 'background:#1f2937; color:white; border-color:#1f2937;'
                        : 'background:white; color:#374151; border-color:#d1d5db;' }}">
                    All Machines
                </button>

                @foreach ($machines as $i => $machine)
                    @php $color = $machinePalette[$i] ?? '#6b7280'; @endphp
                    <button
                        wire:click="filterByMachine({{ $machine->id }})"
                        class="text-xs font-semibold px-3 py-1.5 rounded-full border transition-all duration-150 flex items-center gap-1.5"
                        style="{{ $filterMachineId == $machine->id
                            ? "background:{$color}; color:white; border-color:{$color};"
                            : "background:white; color:{$color}; border-color:{$color};" }}">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
                            background-color:{{ $filterMachineId == $machine->id ? 'white' : $color }};
                            flex-shrink:0;"></span>
                        {{ $machine->machine_name }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Table of Growbag Productions -->
    <div class="min-h-20">
        <table class="w-full border-t border-b table-fixed">
            <thead class="sticky top-[50px] z-10">
                <tr class="text-xs border-b border-gray-300 table-header">
                    <th class="w-[14%] py-3 pe-1 font-semibold text-center ps-6">
                        <a class="text-nowrap" wire:click.prevent="sortBy('date')" href="#">
                            <span>DATE</span>
                            @if ($sortColumn == 'shift_date')
                                <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                            @endif
                        </a>
                    </th>

                    <th class="w-[14%] py-3 pe-1 font-semibold text-center">
                        <a class="text-nowrap" wire:click.prevent="sortBy('shift_name')" href="#">
                            <span>SHIFT</span>
                            @if ($sortColumn == 'shift_name')
                                <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                            @endif
                        </a>
                    </th>

                    <th class="w-[20%] py-3 pe-1 font-semibold text-center">
                        <a class="text-nowrap" wire:click.prevent="sortBy('machine_name')" href="#">
                            <span>MACHINE</span>
                            @if ($sortColumn == 'machine_name')
                                <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                            @endif
                        </a>
                    </th>

                    <th class="w-[18%] py-3 pe-4 font-semibold text-right">
                        <a class="text-nowrap" wire:click.prevent="sortBy('bags_produced')" href="#">
                            <span>PRODUCED (BAGS)</span>
                            @if ($sortColumn == 'bags_produced')
                                <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                            @endif
                        </a>
                    </th>

                    <th class="w-[15%] py-3 pe-4 font-semibold text-right">
                        <a class="text-nowrap" wire:click.prevent="sortBy('eb_units')" href="#">
                            <span>EB UNITS</span>
                            @if ($sortColumn == 'eb_units')
                                <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                            @endif
                        </a>
                    </th>

                    <th class="w-[16%] py-3 pe-4 font-semibold text-right">
                        <a class="text-nowrap" wire:click.prevent="sortBy('eb_price')" href="#">
                            <span>EB COST</span>
                            @if ($sortColumn == 'eb_price')
                                <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                            @else
                                <i class="text-gray-600 fa-solid fa-sort fa-sm"></i>
                            @endif
                        </a>
                    </th>

                    <th class="w-[3%] py-3 pe-3 font-semibold text-center">
                        <abbr title="Advanced Search">
                            <button @click="showHiddenDiv = !showHiddenDiv" class="">
                                <i class="text-gray-700 cursor-pointer hiddendiv-show fa-solid fa-magnifying-glass hover:scale-110 duration-50"></i>
                            </button>
                        </abbr>
                    </th>
                </tr>
            </thead>

            <!-- Advanced Filter Row -->
            <tr x-cloak x-show="showHiddenDiv" x-transition class="h-10 border-b border-gray-200 bg-slate-200">
                <td class="py-3 ps-6">
                    <input type="text"
                        wire:model.live.debounce.500ms="filterDate"
                        class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:border-[#861e1a] focus:ring-[#861e1a]"
                        placeholder="yyyy-mm-dd">
                </td>
                <td class="py-3">
                    <select wire:model.change="filterShiftId"
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:border-[#861e1a] focus:ring-[#861e1a]">
                        <option value="">All</option>
                        @foreach ($shifts as $shift)
                            <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                        @endforeach
                    </select>
                </td>
                <td class="py-3"></td>
                <td class="py-3"></td>
                <td class="py-3"></td>
                <td class="py-3 text-center">
                    <button wire:click="resetFilters"
                            class="text-xs text-red-600 hover:text-red-800 font-medium">
                        Clear
                    </button>
                </td>
                <td class="py-3"></td>
            </tr>

            <tbody class="text-gray-900 tabular-nums">
                @forelse ($productions as $production)
                    <tr class="border-b border-gray-300 hover:bg-[#f6f6fa]">
                        <td class="py-1 text-sm text-nowrap ps-6">
                            <span class="text-gray-900">{{ \Carbon\Carbon::parse($production->shift_date)->format($dateFormat) }}</span>
                        </td>

                        <td class="py-1 text-sm text-nowrap text-center">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                {{ $production->shift_name ?? '-' }}
                            </span>
                        </td>

                        <td class="py-1 text-sm text-center">
                            @if ($production->machine_name)
                                @php
                                    $machineIds   = array_column($machines, 'id');
                                    $machineIndex = array_search($production->growbag_machine_id, $machineIds);
                                    $machineColor = $machineIndex !== false
                                                        ? ($machinePalette[$machineIndex] ?? '#6b7280')
                                                        : '#6b7280';
                                @endphp
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border"
                                    style="border-color: {{ $machineColor }}; color: {{ $machineColor }}; background-color: {{ $machineColor }}15;">
                                    <span style="width:8px;height:8px;border-radius:50%;background:{{ $machineColor }};display:inline-block;flex-shrink:0;"></span>
                                    {{ $production->machine_name }}
                                </span>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </td>

                        <td class="py-1 text-sm text-right pe-4">
                            <span class="text-gray-900">{{ \App\Services\NumberFormatterService::formatToIndianFormat($production->bags_produced, true) }}</span>
                        </td>

                        <td class="py-1 text-sm text-right pe-4">
                            {{ \App\Services\NumberFormatterService::formatToIndianFormat($production->eb_units, true) }}
                        </td>

                        <td class="py-1 text-sm text-right pe-4">
                            {{ \App\Services\NumberFormatterService::formatToIndianCurrency($production->eb_price, 0, true) }}
                        </td>

                        <td></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 text-center text-gray-500 text-sm">
                            No production records found for the selected filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Pagination Controls -->
        <x-pagination :items="$productions" perPageBinding="perPage" />
    </div>
</div>
