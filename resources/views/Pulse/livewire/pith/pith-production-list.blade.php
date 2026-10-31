<div class="w-full">
    <!-- Delete listener -->
    <div x-on:pith-production-deleted.window="alert('Pith Production record deleted successfully.')"></div>

    <!-- Inventory update listener -->
    <div x-on:pith-production-stored.window="alert('Pith load inventory updated successfully.')"></div>

    {{--
        Rendered inline instead of through the layout's <x-slot name="header">: a
        slot is only evaluated on the full page load, so it would survive every
        Livewire update and keep stealing vertical space from the detail view.
        The wrapper mirrors the layout's <header> so the summary view is unchanged.
    --}}
    @unless ($selectedPith)
        <header class="flex bg-white rounded-lg place-content-end">
            <div class="w-full py-2 ps-2 sm:ps-3 lg:ps-4 pe-3">
                <div class="flex place-content-between items-center">
                    <div class="flex items-center gap-4">
                        <div class="relative">
                            <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                            <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                                <x-menu-icon name="pith-production" :size="28" class="text-white" />
                            </div>
                        </div>
                        <div>
                            <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ __('Pith Production') }}</h2>
                            <p class="text-sm text-gray-500 mt-0.5">Shift-wise pith load production records</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="relative w-64">
                            <input type="text" wire:model.live.debounce.500ms="search"
                                class="w-full form-input border border-1 rounded-lg text-sm ps-9 pe-2 py-1.5 border-gray-400 hover:border-[#861e1a] focus:border-[#861e1a] focus:ring-0 focus:ring-[#861e1a] focus:shadow-[0_0_2px_#861e1a]"
                                placeholder="Search..." />
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        </div>

                        <div
                            class="relative"
                            x-data
                            @click.outside="$wire.closeFilters()"
                        >
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
                                    {{-- Date & shift --}}
                                    <div @class(['hidden' => $filterCategory !== 'date'])>
                                        <div class="mb-[18px]">
                                            <span class="mb-2 block caps-label">Date</span>
                                            <x-pulse.advanced-filters.date-presets
                                                :selected="$draftDatePreset"
                                                :from="$draftDateFrom"
                                                :to="$draftDateTo"
                                            />
                                        </div>
                                        <div>
                                            <span class="mb-2 block caps-label">Shift</span>
                                            <x-pulse.advanced-filters.pill-grid
                                                :options="collect($shifts)->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->all()"
                                                :selected="$draftShiftIds"
                                                wire-toggle="toggleDraftShift"
                                            />
                                        </div>
                                    </div>

                                    {{-- Staff --}}
                                    <div @class(['hidden' => $filterCategory !== 'staff'])>
                                        <div>
                                            <span class="mb-2 block caps-label">Staff (select multiple)</span>
                                            <x-pulse.advanced-filters.pill-grid
                                                :options="collect($staffs)->map(fn ($s) => ['value' => $s->id, 'label' => $s->staff_name])->push(['value' => \App\Livewire\Pulse\Pith\PithProductionList::STAFF_UNASSIGNED, 'label' => 'Unassigned'])->all()"
                                                :selected="$draftStaffIds"
                                                wire-toggle="toggleDraftStaff"
                                            />
                                        </div>
                                    </div>

                                    {{-- Output & variance --}}
                                    <div @class(['hidden' => $filterCategory !== 'output'])>
                                        <div class="mb-[18px]">
                                            <span class="mb-2 block caps-label">Output (CFT) range</span>
                                            <x-pulse.advanced-filters.range-inputs
                                                min-model="draftOutMin"
                                                max-model="draftOutMax"
                                            />
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

                                    {{-- Inventory & cost --}}
                                    <div @class(['hidden' => $filterCategory !== 'inventory'])>
                                        <div class="mb-[18px]">
                                            <span class="mb-2 block caps-label">Inventory (CFT) range</span>
                                            <x-pulse.advanced-filters.range-inputs
                                                min-model="draftInvMin"
                                                max-model="draftInvMax"
                                            />
                                        </div>
                                        <div>
                                            <span class="mb-2 block caps-label">EB per CFT (₹) range</span>
                                            <x-pulse.advanced-filters.range-inputs
                                                min-model="draftEbMin"
                                                max-model="draftEbMax"
                                                step="0.1"
                                            />
                                        </div>
                                    </div>

                                </x-pulse.advanced-filters.popover>
                            @endif
                        </div>

                        @if (Auth::user()->allows('pith_production', 'create') && $showActuals)
                            <a href="{{ route('pith.create', ['team' => $teamId]) }}" class="add-btn">
                                <i class="fa-solid fa-plus text-xs"></i>
                                <span>New Production</span>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </header>

        @if (count($filterChips) > 0)
            <div class="px-2 sm:px-3 lg:px-4 pt-2">
                <x-pulse.advanced-filters.chips :chips="$filterChips" :logic="$filterLogic" />
            </div>
        @endif
    @endunless

    @if ($selectedPith)
        @include('pulse::livewire.pith.partials.detailed', compact('productions', 'selectedPith', 'dateFormat', 'timeFormat', 'dateTimeFormat', 'teamId', 'entryPolicy'))
    @else
        @include('pulse::livewire.pith.partials.summary', compact('productions', 'dateFormat', 'timeFormat', 'dateTimeFormat', 'teamId'))
    @endif

    <!-- Inventory Modal -->
    @if ($showInventoryModal)
        @include('pulse::livewire.pith.pith-production-inventory')
    @endif

    <script>
        document.addEventListener('livewire:message-processed', (event) => {
            if (document.getElementById('flash-message')) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    </script>
</div>
