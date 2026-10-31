<!-- resources/views/livewire/manage-devices.blade.php -->
<div x-data="{ open: @entangle('showModal') }" x-init="document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        @this.call('closeModal');
    }
})">
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">
            {{ __('IoT Devices') }}
        </h2>
        @can('view-superadmin-settings')
            <button wire:click="addDeviceModal" class="add-btn text-sm">
                <i class="fa-solid fa-plus text-sm"></i> Add Device
            </button>
        @endcan
    </div>
    <div class="py-2">
        <div class="">
            <div class="bg-white overflow-hidden sm:rounded-lg">
                <div>
                    @if ($showModal)
                        <div class="fixed z-50 inset-0 overflow-y-auto">
                            <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                                <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                                    <div class="absolute inset-0 bg-gray-900 opacity-75"></div>
                                </div>
                                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                                
                                @php
                                    $hiddenThresholdModules = ['fibre', 'growbags'];
                                    $showThresholds = !in_array(strtolower($module ?? ''), $hiddenThresholdModules);
                                @endphp

                                <div class="relative z-10 inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-[0_4px_15px_rgba(0,0,0,0.2)] transform transition-all sm:align-middle sm:max-w-lg sm:w-full sm:h-auto my-1" style="border-radius: 8px;">
                                    <!-- Header -->
                                    <div class="w-full flex justify-between items-center px-6 py-4" style="background-color: #a7181b;">
                                        <h3 class="text-lg leading-6 font-semibold text-white">
                                            {{ $editMode ? 'Edit Device' : 'Add Device' }}
                                        </h3>
                                        <button type="button" wire:click="$set('showModal', false)" class="text-white hover:text-gray-200 text-lg transition-colors">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                    
                                    <!-- Body -->
                                    <div class="bg-white p-[20px]">
                                        <form wire:submit.prevent="{{ $editMode ? 'update' : 'store' }}">
                                            <div class="space-y-4">
                                                <!-- Device ID: Superadmin only; never editable -->
                                                @if ($currentUser->isSuperadmin())
                                                <div>
                                                    <label for="device_id" class="block text-xs font-semibold text-gray-700 mb-1">Device ID</label>
                                                    <input id="device_id" type="number" wire:model="device_id" placeholder="Device ID"
                                                        class="w-full text-sm border border-gray-300 rounded-md bg-gray-100 px-3 py-2 cursor-not-allowed"
                                                        readonly min="1" max="1000">
                                                    @error('device_id')
                                                        <span class="text-red-500 text-sm mt-1">{{ $message }}</span>
                                                    @enderror
                                                    @if (session()->has('error'))
                                                        <div class="text-red-700 text-xs mt-1">{{ session('error') }}</div>
                                                    @endif
                                                </div>
                                                @elseif (session()->has('error'))
                                                    <div class="text-red-700 text-xs">{{ session('error') }}</div>
                                                @endif
                                                
                                                <!-- Module -->
                                                @if ($editMode)
                                                <div>
                                                    <label class="block text-xs font-semibold text-gray-700 mb-1">Module</label>
                                                    <div class="w-full text-sm border border-gray-300 rounded-md bg-gray-100 px-3 py-2 text-gray-600">
                                                        {{ ucfirst($module) }}
                                                    </div>
                                                </div>
                                                
                                                <!-- Eb Units -->
                                                <div>
                                                    <label for="eb_units" class="block text-xs font-semibold text-gray-700 mb-1">Eb Units</label>
                                                    <select id="eb_units" wire:model="eb_units"
                                                        class="w-full text-sm border border-gray-300 rounded-md bg-white px-3 py-2 focus:border-red-700 focus:ring focus:ring-red-700/30 focus:outline-none transition-shadow">
                                                        <option value="kWh">kWh</option>
                                                        <option value="watts">Watts</option>
                                                    </select>
                                                    @error('eb_units')
                                                        <span class="text-red-500 text-sm mt-1">{{ $message }}</span>
                                                    @enderror
                                                </div>
                                                @endif

                                                <!-- Threshold Table -->
                                                @if ($editMode && $showThresholds)
                                                <div class="mt-4 overflow-hidden rounded-md border border-gray-200">
                                                    <table class="min-w-full bg-white">
                                                        <thead style="background-color: #a7181b;">
                                                            <tr>
                                                                <th class="px-4 py-2 text-left text-xs font-semibold text-white">Parameter Description</th>
                                                                <th class="px-4 py-2 text-left text-xs font-semibold text-white">Threshold</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-200">
                                                            <tr>
                                                                <td class="px-4 py-3 text-sm text-gray-900">Max Threshold of Off State (Amps)</td>
                                                                <td class="px-4 py-3">
                                                                    <input type="number" wire:model="off_th" step="any" class="w-full text-sm border border-gray-300 rounded-md bg-white px-3 py-1.5 focus:border-red-700 focus:ring focus:ring-red-700/30 focus:outline-none transition-shadow">
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td class="px-4 py-3 text-sm text-gray-900">Max Threshold of Underloaded State (Amps)</td>
                                                                <td class="px-4 py-3">
                                                                    <input type="number" wire:model="idle_th" step="any" class="w-full text-sm border border-gray-300 rounded-md bg-white px-3 py-1.5 focus:border-red-700 focus:ring focus:ring-red-700/30 focus:outline-none transition-shadow">
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td class="px-4 py-3 text-sm text-gray-900">Max Threshold of Efficient State (Amps)</td>
                                                                <td class="px-4 py-3">
                                                                    <input type="number" wire:model="efficient_th" step="any" class="w-full text-sm border border-gray-300 rounded-md bg-white px-3 py-1.5 focus:border-red-700 focus:ring focus:ring-red-700/30 focus:outline-none transition-shadow">
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td class="px-4 py-3 text-sm text-gray-900">Avg. seconds the device sends every data</td>
                                                                <td class="px-4 py-3">
                                                                    <input type="number" wire:model="duration_device_msg" step="any" class="w-full text-sm border border-gray-300 rounded-md bg-white px-3 py-1.5 focus:border-red-700 focus:ring focus:ring-red-700/30 focus:outline-none transition-shadow">
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td class="px-4 py-3 text-sm text-gray-900">Max. waiting seconds to consider Device as missing data</td>
                                                                <td class="px-4 py-3">
                                                                    <input type="number" wire:model="duration_device_missing" step="any" class="w-full text-sm border border-gray-300 rounded-md bg-white px-3 py-1.5 focus:border-red-700 focus:ring focus:ring-red-700/30 focus:outline-none transition-shadow">
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                @endif
                                            </div>
                                            
                                            <!-- Footer -->
                                            <div class="mt-6 pt-4 border-t border-gray-200 flex justify-end gap-3">
                                                <button type="button" wire:click="$set('showModal', false)" class="cancel-btn">
                                                    Cancel
                                                </button>
                                                <button type="submit" class="save-btn">
                                                    <i class="fa-solid fa-check"></i>
                                                    {{ $editMode ? 'Update Device' : 'Add Device' }}
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Table displaying devices -->
                    <div class="overflow-x-auto w-full">
                        @php
                            $showTableThresholds = false;
                            foreach($devices as $d) {
                                if (!in_array(strtolower($d->module ?? ''), ['fibre', 'growbags'])) {
                                    $showTableThresholds = true;
                                    break;
                                }
                            }
                            if (count($devices) === 0) {
                                $showTableThresholds = true;
                            }
                        @endphp
                        <table class="w-full border-t">
                        <thead>
                            <tr class="table-header text-xs border-b border-gray-300 whitespace-nowrap">
                                @if ($currentUser->isSuperadmin())
                                    <th class="ps-4 py-2 font-semibold text-left">Device Id</th>
                                @endif
                                <th class="px-4 py-2 font-semibold {{ $currentUser->isSuperadmin() ? '' : 'ps-4 text-left' }}">Status</th>
                                <th class="px-4 py-2 font-semibold">Module</th>
                                @if ($showTableThresholds)
                                    <th class="px-4 py-2 font-semibold">Off Threshold</th>
                                    <th class="px-4 py-2 font-semibold">Underloaded Threshold</th>
                                    <th class="px-4 py-2 font-semibold">Efficient Threshold</th>
                                    <th class="px-4 py-2 font-semibold">Msg Interval</th>
                                    <th class="px-4 py-2 font-semibold">Missing Check Duration</th>
                                @endif
                                <th class="px-4 py-2 font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($devices as $device)
                                <tr class="border-b border-gray-300 whitespace-nowrap">
                                    @if ($currentUser->isSuperadmin())
                                        <td class="ps-4 py-2 text-sm text-left">{{ ucfirst($device->device_id) }}
                                        </td>
                                    @endif
                                    <td class="px-4 py-2 text-sm text-center {{ $currentUser->isSuperadmin() ? '' : 'ps-4 text-left' }}">
                                        @if ($device->status === 'active')
                                            <span class="status-badge status-on"><i class="fa-solid fa-circle-check text-[9px]"></i>Active</span>
                                        @else
                                            <span class="status-badge status-off"><i class="fa-solid fa-circle-minus text-[9px]"></i>{{ ucfirst($device->status) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-sm text-center">{{ ucfirst($device->module) }}</td>
                                    @if ($showTableThresholds)
                                        @php
                                            $hideRowThresholds = in_array(strtolower($device->module ?? ''), ['fibre', 'growbags']);
                                        @endphp
                                        @if ($hideRowThresholds)
                                            <td class="px-4 py-2 text-sm text-center text-gray-500">-</td>
                                            <td class="px-4 py-2 text-sm text-center text-gray-500">-</td>
                                            <td class="px-4 py-2 text-sm text-center text-gray-500">-</td>
                                            <td class="px-4 py-2 text-sm text-center text-gray-500">-</td>
                                            <td class="px-4 py-2 text-sm text-center text-gray-500">-</td>
                                        @else
                                            <td class="px-4 py-2 text-sm text-center">{{ $device->off_th }}</td>
                                            <td class="px-4 py-2 text-sm text-center">{{ $device->idle_th }}</td>
                                            <td class="px-4 py-2 text-sm text-center">{{ $device->efficient_th }}</td>
                                            <td class="px-4 py-2 text-sm text-center">{{ $device->duration_device_msg }} seconds</td>
                                            <td class="px-4 py-2 text-sm text-center">{{ $device->duration_device_missing }} seconds</td>
                                        @endif
                                    @endif
                                    <td class="text-center text-xs py-3 space-x-2">
                                        @if ($currentUser->is_superadmin == 1)
                                            <div class="w-24 text-right inline-block">
                                                @if ($device->status === 'active')
                                                    <button wire:click="confirmDeactivate({{ $device->device_id }})" wire:loading.attr="disabled"
                                                        class="deactivate-btn btn-xs">
                                                        <span wire:loading.remove wire:target="confirmDeactivate({{ $device->device_id }})">
                                                            <i class="fa-solid fa-circle-minus"></i> Deactivate
                                                        </span>
                                                        <span wire:loading wire:target="confirmDeactivate({{ $device->device_id }})">
                                                            Loading...
                                                        </span>
                                                    </button>
                                                @else
                                                    <button wire:click="activateDevice({{ $device->device_id }})" wire:loading.attr="disabled"
                                                        class="activate-btn btn-xs">
                                                        <span wire:loading.remove wire:target="activateDevice({{ $device->device_id }})">
                                                            <i class="fa-solid fa-circle-check"></i> Activate
                                                        </span>
                                                        <span wire:loading wire:target="activateDevice({{ $device->device_id }})">
                                                            Activating...
                                                        </span>
                                                    </button>
                                                @endif
                                            </div>
                                            <button wire:click="edit({{ $device->device_id }})" class="edit-btn btn-xs">
                                                <i class="fa-regular fa-pen-to-square"></i>Config
                                            </button>
                                        @endif
                                    </td>
                                    {{-- @if ($currentUser->is_superadmin == 1)
                                    <button onclick="confirmDeletion({{ $device->device_id }})"  class="delete-btn btn-xs"><i class="fa-regular fa-trash-can"></i>Delete</button>
                                    @endif --}}
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                    <x-dialog-modal wire:model.live="confirmingToggle">
                        <x-slot name="title">
                            {{ __('Confirm Deactivation') }}
                        </x-slot>

                        <x-slot name="content">
                            {{ __('Are you sure you want to deactivate this device?') }}
                        </x-slot>

                        <x-slot name="footer" class="gap-2">

                            <x-danger-button wire:click="deactivateDevice"
                                wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="deactivateDevice">
                                    {{ __('Yes, Deactivate') }}
                                </span>
                                <span wire:loading wire:target="deactivateDevice">
                                    {{ __('Deactivating...') }}
                                </span>
                            </x-danger-button>

                            <x-secondary-button wire:click="$set('confirmingToggle', false)">
                                {{ __('Cancel') }}
                            </x-secondary-button>

                        </x-slot>
                    </x-dialog-modal>
                    <script>
                        function confirmDeletion(id) {
                            if (confirm('Are you sure you want to delete this device?')) {
                                @this.call('deleteDevice', id);
                            }
                        }
                    </script>
                </div>
            </div>
        </div>
    </div>
