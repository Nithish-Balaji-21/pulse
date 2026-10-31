{{--
    Shared "Add to Inventory" modal.
    Expects from the including view: $modalTitle, $closeAction, $errorKey, $totalCft
    Inherited from the Livewire component scope: $pithLocations, $pithLocationLoads
--}}
@php
    $assignedTotal = collect($pithLocationLoads)->sum(fn ($load) => is_numeric($load['cft_offloaded'] ?? null) ? (float) $load['cft_offloaded'] : 0);
    $locationGroups = $this->groupedPithLocations();
    // A single ungrouped bucket means the factory has not set up groups; keep the flat list.
    $showGroupHeaders = collect($locationGroups)->contains(fn ($group) => ! $group['is_ungrouped']);
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-800/75 px-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md overflow-hidden">
        <!-- Modal Header -->
        <div class="flex justify-between items-center gap-2 px-5 py-3 border-b border-gray-100">
            <h2 class="caps-label text-[#861e1a] truncate">{{ $modalTitle }}</h2>
            <button type="button" wire:click="{{ $closeAction }}" class="text-gray-400 hover:text-gray-600 shrink-0">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <form wire:submit.prevent="updateLoads">
            <!-- Scrollable Body -->
            <div class="max-h-96 overflow-y-auto px-5">
                @if ($errors->has($errorKey))
                    <p class="text-red-600 text-sm pt-3">{{ $errors->first($errorKey) }}</p>
                @endif
                <div class="flex justify-between items-baseline pt-3 pb-1 caps-label">
                    <span>Location</span>
                    <span>Inward CFT</span>
                </div>
                @foreach ($locationGroups as $group)
                    @if ($showGroupHeaders)
                        <div wire:key="inventory-group-{{ $group['id'] }}"
                            class="flex items-center justify-between gap-3 pt-3 pb-1 mt-1 border-t border-gray-200 sticky top-0 bg-white z-10">
                            <span class="caps-label {{ $group['is_ungrouped'] ? 'text-gray-500' : 'text-[#861e1a]' }} truncate">
                                {{ $group['name'] }}
                            </span>
                            <span class="text-xs font-semibold text-gray-500 shrink-0" style="font-variant-numeric: tabular-nums;">
                                {{ \App\Services\NumberFormatterService::formatCompactNumber($group['assigned']) }} cft
                            </span>
                        </div>
                    @endif

                    @foreach ($group['locations'] as $location)
                        @php $isArchived = !empty($pithLocationLoads[$location['id']]['is_archived']); @endphp
                        <div wire:key="inventory-location-{{ $location['id'] }}"
                            class="flex items-center justify-between gap-4 py-1.5 border-t border-gray-100">
                            <span class="text-sm text-gray-700 min-w-0 truncate {{ $showGroupHeaders ? 'ps-3' : '' }}">{{ $location['name'] }}</span>
                            <input type="number"
                                wire:model.live="pithLocationLoads.{{ $location['id'] }}.cft_offloaded"
                                class="input-type no-spinner text-sm w-28 text-right shrink-0 {{ $isArchived ? 'input-locked' : '' }}"
                                min="0"
                                inputmode="numeric"
                                placeholder="0"
                                onfocus="if(Number(this.value) === 0) { this.value = ''; this.dispatchEvent(new Event('input')); }"
                                @if ($isArchived) disabled @endif
                            />
                        </div>
                    @endforeach
                @endforeach
            </div>
            <!-- Modal Footer -->
            <div class="flex justify-between items-center gap-3 px-5 py-3 border-t border-gray-100 bg-[#fbfaf8] text-sm">
                <span class="text-xs {{ $errors->has($errorKey) ? 'text-red-600' : 'text-gray-500' }}" style="font-variant-numeric: tabular-nums;">
                    Assigned <span class="font-semibold">{{ \App\Services\NumberFormatterService::formatCompactNumber($assignedTotal) }}</span>
                    of {{ \App\Services\NumberFormatterService::formatCompactNumber($totalCft ?: 0) }} CFT produced
                </span>
                <button type="submit" class="save-btn">Save</button>
            </div>
        </form>
    </div>
</div>
