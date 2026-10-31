<div>
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">
            {{ __('Pith Load Settings: Locations') }}
        </h2>
        @if (session()->has('success'))
            <div class="text-green-500 text-md font-semibold text-center grow mx-4">{{ session('success') }}</div>
        @endif
        @if (session()->has('error'))
            <div class="text-red-500 text-md font-semibold text-center grow mx-4">{{ session('error') }}</div>
        @endif
        <div>
            <button wire:click="openPithLocationModal" class="add-btn text-sm">
                <i class="fa-solid fa-plus text-sm"></i> Add Location
            </button>
        </div>
    </div>
    <div class="py-2 bg-white overflow-hidden sm:rounded-lg">
        <!-- Modal Popup -->
        @if ($isModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-5">
                <div class="bg-white sm:w-full md:w-2/3 lg:w-1/3 h-auto p-4 rounded-lg overflow-y-auto">
                    <!-- Red Cross Button for Closing -->
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-medium">{{ $editing ? 'Edit Location' : 'Add Location' }}</h3>
                        <button wire:click="closePithLocationModal"
                            class="text-red-500 hover:text-red-700 text-lg">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <div class="flex">
                        <div class="w-1/3"></div>
                        @if (session()->has('error'))
                            <div class="w-2/3 text-red-500 text-md place-content-center text-left font-semibold">
                                {{ session('error') }}
                            </div>
                        @endif
                    </div>
                    <form wire:submit.prevent="{{ $editing ? 'update' : 'store' }}">
                        <div class="space-y-4">
                            <!-- Location Name Input -->
                            <div class="flex items-center">
                                <label for="locationName"
                                    class="w-1/3 place-content-center text-sm text-nowrap">Location Name</label>
                                <input type="text" id="locationName" wire:model.blur="pithLocationName"
                                    maxlength="{{ \App\Http\Controllers\Pulse\PithLocationsController::NAME_MAX_LENGTH }}"
                                    class="w-2/3 text-sm input-type">
                                @error('locationName')
                                    <span class="text-red-500 text-sm">{{ $message }}</span>
                                @enderror
                            </div>
                            <p class="text-xs text-gray-500 ps-[33%]">Up to {{ \App\Http\Controllers\Pulse\PithLocationsController::NAME_MAX_LENGTH }} characters. Name the spot itself (e.g. "Bunker", "Near Sump") and use the group below for the site — avoid numbered variants like "East 2".</p>

                            @if ($similarNameWarning)
                                <div class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i>
                                    <span>{{ $similarNameWarning }}</span>
                                </div>
                            @endif

                            <!-- Group Select -->
                            <div class="flex items-center">
                                <label for="groupId"
                                    class="w-1/3 place-content-center text-sm text-nowrap">Group</label>
                                <select id="groupId" wire:model="groupId" class="w-2/3 text-sm input-type">
                                    <option value="">Ungrouped</option>
                                    @foreach ($pithLocationGroups as $group)
                                        <option value="{{ $group->id }}">
                                            {{ $group->name }}{{ $group->is_active ? '' : ' (inactive)' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            @if (empty($pithLocationGroups))
                                <p class="text-xs text-gray-500 ps-[33%]">No groups yet — add them under Pith Load Settings: Location Groups.</p>
                            @endif

                            <div class="flex items-center">
                                <label for="lowStockAlertValue"
                                    class="w-1/3 place-content-center text-sm text-nowrap">Low Stock Alert Value</label>
                                <input type="number" id="lowStockAlertValue" wire:model="lowStockAlertValue"
                                    min="0" step="1" placeholder="-"
                                    class="w-2/3 text-sm input-type">
                            </div>
                            <p class="text-xs text-gray-500 ps-[33%]">Send an alert if remaining loads are fewer than this value. Leave empty or set to 0 to turn off alerts.</p>

                            <!-- Modal Footer -->
                            <div class="flex justify-end space-x-2 mt-4 text-sm">
                                <button type="submit" class="save-btn">
                                    <i class="fa-solid {{ $editing ? 'fa-rotate' : 'fa-plus' }}"></i>
                                    {{ $editing ? 'Update Location' : 'Add Location' }}
                                </button>
                                <button type="button" wire:click="closePithLocationModal"
                                    class="cancel-btn">Cancel</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
        <!-- Locations Table -->
        <div class="overflow-x-auto overflow-y-auto">
            <table class="w-full border-t">
                <thead>
                    <tr class="table-header text-xs border-b border-gray-300">
                        <th class="py-2 pe-1 font-semibold ps-4 text-left">Location Name</th>
                        <th class="py-2 pe-1 font-semibold text-left">Group</th>
                        <th class="py-2 pe-1 font-semibold text-center">Stock (cft)</th>
                        <th class="py-2 pe-1 font-semibold text-center">Low Stock Alert Value</th>
                        <th class="py-2 pe-1 font-semibold">Status</th>
                        <th class="py-2 pe-1 font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pithLocations as $location)
                        @php
                            $remainingCft = (float) ($location->remaining_cft ?? 0);
                            $toggleBlocked = $location->is_active && $remainingCft > 0;
                        @endphp
                        <tr class="table-header text-xs border-b border-gray-300">
                            <td class="text-left text-sm py-3 ps-4 capitalize">{{ $location->name }}</td>
                            <td class="text-left text-sm py-3">
                                @if (empty($location->group_name))
                                    <span class="text-gray-500">Ungrouped</span>
                                @else
                                    {{ $location->group_name }}
                                @endif
                            </td>
                            <td class="text-center text-sm py-3 {{ $remainingCft > 0 ? 'text-gray-800' : 'text-gray-400' }}">
                                {{ \App\Services\NumberFormatterService::formatToIndianFormat($remainingCft, false, 0) }}
                            </td>
                            <td class="text-center text-sm py-3">
                                @if(empty($location->low_stock_alert_value))
                                    -
                                @else
                                    {{ $location->low_stock_alert_value }}
                                @endif
                            </td>
                            <td class="text-center text-sm py-3">
                                @if ($location->is_active)
                                    <span class="status-badge status-on"><i class="fa-solid fa-circle-check text-[9px]"></i>Active</span>
                                @else
                                    <span class="status-badge status-off"><i class="fa-solid fa-circle-minus text-[9px]"></i>Inactive</span>
                                @endif
                            </td>

                            <td class="text-center text-xs py-3 space-x-2">
                                <div class="w-24 text-right inline-block">
                                    <button wire:click="confirmToggle({{ $location->id }})"
                                        @if ($toggleBlocked) title="Empty this location before deactivating it" @endif
                                        {{-- Stays clickable when blocked: confirmToggle() flashes the reason
                                             ("still holds N cft"), which a disabled button would swallow. --}}
                                        class="{{ $location->is_active ? 'deactivate-btn' : 'activate-btn' }} btn-xs {{ $toggleBlocked ? 'opacity-60' : '' }}">
                                        <i class="fa-solid {{ $location->is_active ? 'fa-circle-minus' : 'fa-circle-check' }}"></i>
                                        {{ $location->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </div>
                                <div class="w-fit inline-block">
                                    <button wire:click="edit({{ $location->id }})" class="edit-btn btn-xs">
                                        <i class="fa-regular fa-pen-to-square"></i>Edit
                                    </button>
                                </div>&nbsp;
                                <div class="w-fit inline-block">
                                    <button onclick="confirmDeletion({{ $location->id }})" class="delete-btn btn-xs">
                                        <i class="fa-regular fa-trash-can"></i>Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($confirmingToggle)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-500/50">
                <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">{{ __('Confirm Status Change') }}</h3>
                        <button type="button" wire:click="cancelToggle" class="text-gray-500 hover:text-gray-700">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <div class="text-sm text-gray-700 mb-6">
                        {{ __('Are you sure you want to change the status of this location?') }}
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" wire:click="cancelToggle" class="cancel-btn">
                            {{ __('Cancel') }}
                        </button>
                        <button type="button" wire:click="toggleActive" wire:loading.attr="disabled" class="save-btn">
                            {{ __('Yes, Change') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
<script>
    function confirmDeletion(id) {
        if (confirm('Are you sure you want to delete this location?')) {
            @this.call('delete', id);
        }
    }
</script>
