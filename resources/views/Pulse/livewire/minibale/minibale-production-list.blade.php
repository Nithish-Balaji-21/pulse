<div class="w-full" x-data="{ showStockDialog: @entangle('showStockDialog') }">
    <!-- Delete listener -->
    <div x-on:fibre-production-deleted.window="alert('Fibre Production record deleted successfully.')"></div>

    <header class="flex bg-white rounded-lg place-content-end">
        <div class="w-full py-2 ps-2 sm:ps-3 lg:ps-4 pe-3">
            <div class="flex place-content-between items-center">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                        <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                            <x-menu-icon name="fibre-bales" :size="28" class="text-white" />
                        </div>
                    </div>
                    <div>
                        <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Fibre Bales Production') }}</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Daily fibre bales production records</p>
                    </div>
                </div>
                <div class="flex items-center gap-2.5">
                    <div class="relative w-64">
                        <input type="text" wire:model.live.debounce.500ms="search"
                            class="w-full form-input border border-1 rounded-lg text-sm ps-9 pe-2 py-1.5 border-gray-400 hover:border-[#861e1a] focus:border-[#861e1a] focus:ring-0 focus:ring-[#861e1a] focus:shadow-[0_0_2px_#861e1a]"
                            placeholder="Search..." />
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    </div>

                    <div class="relative" x-data @click.outside="$wire.closeFilters()">
                        <x-pulse.advanced-filters.button
                            :count="$this->activeFilterCount()"
                            :open="$filtersOpen"
                        />

                        @if ($filtersOpen)
                            <x-pulse.advanced-filters.popover
                                :categories="$filterCategories"
                                :active-category="$filterCategory"
                                :match-count="$matchCount"
                                :total-count="$totalForFilters"
                                :category-dots="$categoryDots"
                            >
                                <div @class(['hidden' => $filterCategory !== 'date'])>
                                    <div>
                                        <span class="mb-2 block caps-label">Date</span>
                                        <x-pulse.advanced-filters.date-presets
                                            :selected="$draftDatePreset"
                                            :from="$draftDateFrom"
                                            :to="$draftDateTo"
                                        />
                                    </div>
                                </div>

                                <div @class(['hidden' => $filterCategory !== 'output'])>
                                    <div class="mb-[18px]">
                                        <span class="mb-2 block caps-label">Output (bales) range</span>
                                        <x-pulse.advanced-filters.range-inputs min-model="draftOutMin" max-model="draftOutMax" />
                                    </div>
                                    <div>
                                        <span class="mb-2 block caps-label">Variance vs. estimate</span>
                                        <x-pulse.advanced-filters.pill-grid
                                            :options="[
                                                ['value' => 'over', 'label' => 'Over estimate'],
                                                ['value' => 'under', 'label' => 'Under estimate'],
                                            ]"
                                            :selected="$draftVariance"
                                            wire-toggle="toggleDraftVariance"
                                        />
                                        <p class="mt-1.5 text-xs text-gray-500">Compares Output to the est. figure shown in the table.</p>
                                    </div>
                                </div>

                                <div @class(['hidden' => $filterCategory !== 'staff'])>
                                    <span class="mb-2 block caps-label">Staff (select multiple)</span>
                                    <x-pulse.advanced-filters.pill-grid
                                        :options="collect($staffs)->map(fn ($s) => ['value' => $s->id, 'label' => $s->staff_name])->push(['value' => \App\Livewire\Pulse\MiniBale\MiniBaleProductionList::STAFF_UNASSIGNED, 'label' => 'Unassigned'])->all()"
                                        :selected="$draftStaffIds"
                                        wire-toggle="toggleDraftStaff"
                                    />
                                </div>

                                <div @class(['hidden' => $filterCategory !== 'stock'])>
                                    <div class="mb-[18px]">
                                        <span class="mb-2 block caps-label">In stock (bales) range</span>
                                        <x-pulse.advanced-filters.range-inputs min-model="draftStockMin" max-model="draftStockMax" />
                                    </div>
                                    <div>
                                        <span class="mb-2 block caps-label">Sold (bales) range</span>
                                        <x-pulse.advanced-filters.range-inputs min-model="draftSoldMin" max-model="draftSoldMax" />
                                    </div>
                                </div>
                            </x-pulse.advanced-filters.popover>
                        @endif
                    </div>

                    @if (Auth::user()->allows('fibre_bales', 'create') && $showActuals)
                        <a href="{{ route('fibre.create', ['team' => $teamId]) }}" class="add-btn">
                            <i class="fa-solid fa-plus text-xs"></i>
                            <span>New Production</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </header>

    @if (count($filterChips) > 0)
        <div class="pt-2">
            <x-pulse.advanced-filters.chips :chips="$filterChips" :logic="$filterLogic" />
        </div>
    @endif

    {{-- Flash Message --}}
    @if (session('message') || session('error'))
        <div class="relative">
            @if (session('message'))
                <div id="flash-message"
                    class="z-50 bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 mt-2 text-gray-700 px-6 py-3 rounded-lg fixed top-4 left-1/2 transform -translate-x-1/2 shadow-xl animate-slide-down opacity-100 transition-opacity duration-500 ease-in-out"
                    role="alert">
                    <div class="flex items-center gap-3">
                        <div class="shrink-0">
                            <i class="fa-solid fa-circle-check text-green-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-green-800">Success!</p>
                            <p class="text-sm text-green-700">{{ session('message') }}</p>
                        </div>
                    </div>
                </div>
            @endif
            @if (session('error'))
                <div id="flash-error"
                    class="z-50 bg-gradient-to-r from-red-50 to-rose-50 border-l-4 border-red-500 mt-2 text-gray-700 px-6 py-3 rounded-lg fixed top-4 left-1/2 transform -translate-x-1/2 shadow-xl animate-slide-down opacity-100 transition-opacity duration-500 ease-in-out"
                    role="alert">
                    <div class="flex items-center gap-3">
                        <div class="shrink-0">
                            <i class="fa-solid fa-circle-exclamation text-red-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-red-800">Error</p>
                            <p class="text-sm text-red-700">{{ session('error') }}</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Machine pill filter tabs (synced with applied machineIds) --}}
    @if ($columns['machine'] && count($machines) >= 1)
        @php
            $machinePalette = config('colors.minibales_charts.machines', []);
            $activeMachineId = count($machineIds) === 1 ? (int) $machineIds[0] : null;
        @endphp
        <div class="bg-white rounded-lg shadow-xs border border-gray-200 p-3 mt-1.5 mb-1.5">
            <div class="flex items-center gap-2 mb-1.5">
                <i class="fa-solid fa-cogs text-gray-600"></i>
                <span class="text-xs font-semibold text-gray-700">Filter by Machine:</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <button
                    wire:click="filterByMachine(null)"
                    class="text-xs font-semibold px-3 py-1 rounded-full border transition-all duration-150"
                    style="{{ $activeMachineId === null && count($machineIds) === 0
                        ? 'background:#1f2937; color:white; border-color:#1f2937;'
                        : 'background:white; color:#374151; border-color:#d1d5db;' }}">
                    All Machines
                </button>

                @foreach ($machines as $i => $machine)
                    @php
                        $mid = is_array($machine) ? $machine['id'] : $machine->id;
                        $mname = is_array($machine) ? $machine['machine_name'] : $machine->machine_name;
                        $color = $machinePalette[$i] ?? '#6b7280';
                        $isOn = $activeMachineId === (int) $mid;
                    @endphp
                    <button
                        wire:click="filterByMachine({{ $mid }})"
                        class="text-xs font-semibold px-3 py-1 rounded-full border transition-all duration-150 flex items-center gap-1.5"
                        style="{{ $isOn
                            ? "background:{$color}; color:white; border-color:{$color};"
                            : "background:white; color:{$color}; border-color:{$color};" }}">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;
                            background-color:{{ $isOn ? 'white' : $color }};
                            flex-shrink:0;"></span>
                        {{ $mname }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @php
        $optionalWidth =
            ($columns['machine'] && count($machines) >= 1 ? 10 : 0) +
            ($columns['staff'] ? 11 : 0) +
            ($columns['stock'] ? 31 : 0) +
            ($columns['comments'] ? 14 : 0);
        $outputWidth = max(18, min(35, 100 - 23 - $optionalWidth));
    @endphp

    <!-- Table of Fibre Productions -->
    <div class="min-h-20">

        <table class="w-full border border-gray-300 table-fixed border-collapse">
            <thead class="sticky top-[50px] z-10">
                <tr class="text-xs border-b border-gray-300 table-header bg-slate-100">
                    <th class="w-[12%] py-2.5 px-2 font-semibold text-center ps-4">
                        <a class="text-nowrap inline-flex items-center justify-center gap-1" wire:click.prevent="sortBy('shift_date')" href="#">
                            <span>DATE</span>
                            @if ($sortColumn == 'shift_date')
                                <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                            @else
                                <i class="text-gray-400 fa-solid fa-sort fa-xs"></i>
                            @endif
                        </a>
                    </th>

                    @if ($columns['staff'])
                        <th class="w-[11%] py-2.5 px-2 font-semibold text-center">
                            <a class="text-nowrap inline-flex items-center justify-center gap-1" wire:click.prevent="sortBy('staff_id')" href="#">
                                <span>STAFF</span>
                                @if ($sortColumn == 'staff_id')
                                    <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                                @else
                                    <i class="text-gray-400 fa-solid fa-sort fa-xs"></i>
                                @endif
                            </a>
                        </th>
                    @endif

                    <th class="py-2.5 px-4 font-semibold text-right pe-6" style="width: {{ $outputWidth }}%">
                        <a class="text-nowrap inline-flex items-center justify-end gap-1" wire:click.prevent="sortBy('output')" href="#">
                            <span>OUTPUT (BALES)</span>
                            @if ($sortColumn == 'output')
                                <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                            @else
                                <i class="text-gray-400 fa-solid fa-sort fa-xs"></i>
                            @endif
                        </a>
                    </th>

                    @if ($columns['stock'])
                        <th class="w-[10%] py-2.5 px-2 font-semibold text-right pe-4">
                            <a class="text-nowrap inline-flex items-center justify-end gap-1 text-gray-700" wire:click.prevent="sortBy('sold')" href="#">
                                <span>SOLD</span>
                                @if ($sortColumn == 'sold')
                                    <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                                @else
                                    <i class="text-gray-400 fa-solid fa-sort fa-xs"></i>
                                @endif
                            </a>
                        </th>
                        <th class="w-[10%] py-2.5 px-2 font-semibold text-right pe-4">
                            <a class="text-nowrap inline-flex items-center justify-end gap-1 text-gray-700" wire:click.prevent="sortBy('correction')" href="#">
                                <span>CORRECTION</span>
                                @if ($sortColumn == 'correction')
                                    <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                                @else
                                    <i class="text-gray-400 fa-solid fa-sort fa-xs"></i>
                                @endif
                            </a>
                        </th>
                        <th class="w-[11%] py-2.5 px-2 font-semibold text-right pe-4">
                            <a class="text-nowrap inline-flex items-center justify-end gap-1" wire:click.prevent="sortBy('bales_in_stock')" href="#">
                                <span>IN STOCK</span>
                                @if ($sortColumn == 'bales_in_stock')
                                    <i class="text-gray-600 fa fa-caret-{{ $sortDirection == 'asc' ? 'up' : 'down' }} fa-sm"></i>
                                @else
                                    <i class="text-gray-400 fa-solid fa-sort fa-xs"></i>
                                @endif
                            </a>
                        </th>
                    @endif

                    @if ($columns['comments'])
                        <th class="w-[14%] py-2.5 px-2 font-semibold text-center">
                            <span class="text-gray-700">COMMENTS</span>
                        </th>
                    @endif

                    <th class="w-[8%] py-2.5 px-2 font-semibold text-center">
                        <span class="text-gray-700">ACTIONS</span>
                    </th>
                </tr>
            </thead>

            <tbody class="text-gray-900 tabular-nums">
                @php $rowPy = 'py-1.5'; @endphp
                @foreach ($productions as $production)
                    <tr class="border-b border-gray-200 hover:bg-[#f6f6fa]">
                        <td class="{{ $rowPy }} px-2 text-sm text-nowrap text-center ps-4">
                            <span class="text-gray-900">{{ \Carbon\Carbon::parse($production->shift_date)->format($dateFormat) }}</span>
                        </td>

                        @if ($columns['staff'])
                            <td class="{{ $rowPy }} px-2 text-sm text-nowrap text-center">
                                <span class="text-gray-900">{{ $production->staff->staff_name ?? '-' }}</span>
                            </td>
                        @endif

                        <td class="{{ $rowPy }} px-4 text-sm pe-4 text-center">
                            <x-production-output-cell
                                :expected="$production->bales_expected"
                                :actual="$production->bales_produced"
                                :scale="$barScale"
                                :showActuals="$showActuals" />
                        </td>

                        @if ($columns['stock'])
                            <td class="{{ $rowPy }} px-2 text-sm text-right pe-4 text-gray-900">
                                <div class="inline-flex items-center justify-end gap-1.5">
                                    <span>{{ $production->sold !== null ? \App\Services\NumberFormatterService::formatToIndianFormat($production->sold, false, 0) : '-' }}</span>
                                    @if (Auth::user()->allows('fibre_bales', 'edit'))
                                        @if ($production->canEditRecord())
                                            <button type="button"
                                                    wire:click="openSoldDialog({{ $production->id }})"
                                                    class="inline-flex items-center justify-center w-6 h-6 text-blue-600 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-md transition-colors shadow-xs shrink-0"
                                                    title="Edit sold">
                                                <i class="fa-regular fa-pen-to-square text-[10px]"></i>
                                            </button>
                                        @else
                                            <button type="button" disabled
                                                    class="inline-flex items-center justify-center w-6 h-6 text-gray-400 bg-gray-100 border border-gray-200 rounded-md opacity-40 cursor-not-allowed shrink-0"
                                                    title="{{ $entryPolicy->policyMessage() }}">
                                                <i class="fa-regular fa-pen-to-square text-[10px]"></i>
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                            <td class="{{ $rowPy }} px-2 text-sm text-right pe-4">
                                <div class="inline-flex items-center justify-end gap-1.5">
                                    @if ($production->correction)
                                        <span class="{{ $production->correction > 0 ? 'text-green-700' : 'text-red-600' }}" title="{{ $production->correction_comment }}">
                                            {{ $production->correction > 0 ? '+' : '' }}{{ \App\Services\NumberFormatterService::formatToIndianFormat($production->correction, false, 0) }}
                                        </span>
                                    @else
                                        <span class="text-gray-500">-</span>
                                    @endif
                                    @if (Auth::user()->allows('fibre_bales', 'edit'))
                                        @if ($production->canEditRecord())
                                            <button type="button"
                                                    wire:click="openCorrectionDialog({{ $production->id }})"
                                                    class="inline-flex items-center justify-center w-6 h-6 text-amber-600 bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded-md transition-colors shadow-xs shrink-0"
                                                    title="Edit correction">
                                                <i class="fa-regular fa-pen-to-square text-[10px]"></i>
                                            </button>
                                        @else
                                            <button type="button" disabled
                                                    class="inline-flex items-center justify-center w-6 h-6 text-gray-400 bg-gray-100 border border-gray-200 rounded-md opacity-40 cursor-not-allowed shrink-0"
                                                    title="{{ $entryPolicy->policyMessage() }}">
                                                <i class="fa-regular fa-pen-to-square text-[10px]"></i>
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                            <td class="{{ $rowPy }} px-2 text-sm text-right pe-4">
                                <x-production-stock-cell
                                    :level="$stockLevels[$production->id] ?? null"
                                    :dateFormat="$dateFormat" />
                            </td>
                        @endif

                        @if ($columns['comments'])
                            <td class="{{ $rowPy }} px-2 text-sm text-left">
                                <span class="text-gray-900">{{ \Illuminate\Support\Str::limit($production->comments, 20, '...') }}</span>
                            </td>
                        @endif

                        <td class="{{ $rowPy }} px-2 text-sm text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                @if (Auth::user()->allows('fibre_bales', 'edit'))
                                    @if ($production->isEditable())
                                        <a href="{{ route('fibre.edit', ['team' => $teamId, 'id' => $production->id]) }}"
                                           class="inline-flex items-center justify-center w-7 h-7 text-blue-600 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-md transition-colors shadow-xs"
                                           title="Edit">
                                            <i class="fa-regular fa-pen-to-square text-xs"></i>
                                        </a>
                                    @else
                                        <button type="button" disabled
                                                class="inline-flex items-center justify-center w-7 h-7 text-gray-400 bg-gray-100 border border-gray-200 rounded-md opacity-40 cursor-not-allowed"
                                                title="{{ $entryPolicy->policyMessage() }}">
                                            <i class="fa-regular fa-pen-to-square text-xs"></i>
                                        </button>
                                    @endif
                                @endif

                                @if (Auth::user()->allows('fibre_bales', 'delete'))
                                    @if ($production->canDeleteRecord())
                                        <button wire:click="confirmDeletion({{ $production->id }})"
                                                class="inline-flex items-center justify-center w-7 h-7 text-red-600 bg-red-50 hover:bg-red-100 border border-red-200 rounded-md transition-colors shadow-xs"
                                                title="Delete">
                                            <i class="fa-regular fa-trash-can text-xs"></i>
                                        </button>
                                    @else
                                        <button type="button" disabled
                                                class="inline-flex items-center justify-center w-7 h-7 text-gray-400 bg-gray-100 border border-gray-200 rounded-md opacity-40 cursor-not-allowed"
                                                title="{{ $entryPolicy->policyMessage() }}">
                                            <i class="fa-regular fa-trash-can text-xs"></i>
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Pagination Controls -->
        <x-pagination :items="$productions" perPageBinding="perPage" />
    </div>

    <!-- DELETE MODAL -->
    @if ($showDeleteConfirmationDialog)
        <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center">
            <div class="bg-white p-6 rounded-xl shadow-2xl w-full max-w-md mx-4">
                <div class="flex items-center gap-3 mb-4">
                    <div class="p-3 rounded-full bg-red-100">
                        <i class="fa-solid fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Confirm Deletion</h2>
                        <p class="text-sm text-gray-600 mt-1">This action cannot be undone</p>
                    </div>
                </div>

                <p class="text-sm text-gray-700 mb-6">
                    Are you sure you want to delete this fibre bales production record? All associated data will be permanently removed.
                </p>

                <div class="flex justify-end gap-3">
                    <button class="cancel-btn"
                            wire:click="closeDeleteModal">
                        Cancel
                    </button>
                    <button class="delete-btn"
                            wire:click="deleteProduction">
                        Delete
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- STOCK DIALOG -->
    <div x-cloak
         x-show="showStockDialog"
         wire:click.self="closeStockDialog"
         x-on:keydown.escape.window="$wire.closeStockDialog()"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          x-data="{
            stockDialogMode: @entangle('stockDialogMode'),
            soldQty: @entangle('soldQuantity'),
            amount: @entangle('correctionAmount'),
            type: @entangle('correctionType'),
            comment: @entangle('correctionComment'),
            opening: @entangle('stockDialogOpening'),
            produced: @entangle('stockDialogProduced'),
            get signed() {
                const value = Number(this.amount || 0);
                return this.type === 'deduct' ? -value : value;
            },
            get resulting() {
                return Number(this.opening || 0) + Number(this.produced || 0) - Number(this.soldQty || 0) + this.signed;
            },
            get deltaText() {
                if (this.stockDialogMode === 'sold') {
                    const qty = Number(this.soldQty || 0);
                    return qty > 0 ? `(-${this.fmt(qty)} bales sold)` : '';
                } else {
                    const amt = Number(this.amount || 0);
                    if (amt === 0) return '';
                    return this.type === 'deduct'
                        ? `(-${this.fmt(amt)} bales deducted)`
                        : `(+${this.fmt(amt)} bales added)`;
                }
            },
            fmt(n) { return Math.round(n).toLocaleString('en-IN'); },
            bump(step) { this.soldQty = Number(this.soldQty || 0) + step; }
         }">
        <div wire:click.stop
             class="w-full max-w-md rounded-2xl bg-white shadow-2xl border border-gray-200">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                <div>
                    <h2 class="text-base font-normal text-gray-900">{{ $stockDialogMode === 'sold' ? 'Sold' : 'Correction' }}</h2>
                    <p class="text-xs text-gray-500">
                        {{ $stockDialogMode === 'sold' ? 'Update sold quantity for this production date.' : 'Add or deduct a stock correction for this production date.' }}
                    </p>
                </div>
                <button type="button" wire:click="closeStockDialog" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
                    <i class="fa-solid fa-times text-sm"></i>
                </button>
            </div>

            <div class="space-y-3 px-5 py-3.5">
                @error('stockDialogProduction')
                    <p class="text-red-500 text-xs">{{ $message }}</p>
                @enderror

                @if ($stockDialogMode === 'sold')
                    <div class="space-y-2.5">
                        <div>
                            <div class="relative">
                                <input type="number" x-model="soldQty" id="soldQuantityModal" min="0"
                                       class="input-type w-full text-base font-normal pr-16" x-on:focus="$event.target.select()" />
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none">bales</span>
                            </div>
                            @error('soldQuantity')
                                <span class="text-red-500 text-xs">{{ $message }}</span>
                            @enderror
                        </div>

                        @if ($lastEntryText)
                            <p class="text-xs text-gray-500">{{ $lastEntryText }}</p>
                        @endif
                    </div>
                @else
                    <div class="space-y-2.5">
                        <div>
                            <label class="text-xs font-normal text-gray-700">Correction Type</label>
                            <div class="grid grid-cols-2 gap-2 mt-1">
                                <button type="button" @click="type = 'add'"
                                        :class="type === 'add' ? 'bg-[#861e1a] text-white border-[#861e1a]' : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'"
                                        class="rounded-lg border py-1.5 text-xs font-normal transition-colors">
                                    Add
                                </button>
                                <button type="button" @click="type = 'deduct'"
                                        :class="type === 'deduct' ? 'bg-[#861e1a] text-white border-[#861e1a]' : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'"
                                        class="rounded-lg border py-1.5 text-xs font-normal transition-colors">
                                    Subt
                                </button>
                            </div>
                            @error('correctionType')
                                <span class="text-red-500 text-xs">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="correctionAmountModal" class="text-xs font-normal text-gray-700">Amount</label>
                            <div class="relative mt-1">
                                <input type="number" x-model="amount" id="correctionAmountModal" min="0" placeholder="0"
                                       class="input-type w-full text-base font-normal pr-16" x-on:focus="$event.target.select()" />
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none">bales</span>
                            </div>
                            @error('correctionAmount')
                                <span class="text-red-500 text-xs">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="correctionNoteModal" class="text-xs font-normal text-gray-700">Correction Note</label>
                            <textarea id="correctionNoteModal" x-model="comment" rows="2"
                                      placeholder="Reason for the correction (optional)"
                                      class="input-type mt-1 w-full text-xs font-normal resize-y"></textarea>
                            @error('correctionComment')
                                <span class="text-red-500 text-xs">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                @endif

                <div class="rounded-xl px-3.5 py-2 flex items-center justify-between text-xs font-normal"
                     :class="resulting < 0 ? 'bg-rose-50 border border-rose-200 text-rose-800' : 'bg-green-50 border border-green-200 text-green-800'">
                    <span class="text-gray-600 font-normal">Resulting Stock:</span>
                    <span class="font-normal"><span x-text="fmt(resulting)"></span> bales</span>
                </div>

                <p class="text-xs text-rose-600 flex items-center gap-1.5" x-show="resulting < 0">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>This leaves stock negative — check the sold figure or add a correction.</span>
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3 bg-gray-50/50">
                <button type="button" wire:click="closeStockDialog" class="cancel-btn text-xs font-normal py-1.5 px-3">Cancel</button>
                <button type="button" wire:click="saveStockDialog" class="add-btn text-xs font-normal py-1.5 px-3">Save</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const flashMessage = document.getElementById('flash-message');
            const flashError = document.getElementById('flash-error');
            [flashMessage, flashError].forEach((flash) => {
                if (! flash) {
                    return;
                }
                setTimeout(() => {
                    flash.classList.add('opacity-0', 'translate-y-[-20px]');
                    setTimeout(() => flash.remove(), 500);
                }, 3000);
            });
        });
    </script>
</div>
