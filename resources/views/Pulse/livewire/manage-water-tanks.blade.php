<div>
    @php
        $isSuperadmin = Auth::user()->isSuperadmin();
        $isOwnerOnly = ! $isSuperadmin && Auth::user()->getCurrentTeamRole() === 'owner';
    @endphp
    <!-- Header -->
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">{{ __('Water Tanks') }}</h2>

        @if (session()->has('success'))
            <div class="text-green-500 text-md font-semibold text-center grow mx-4">
                {{ session('success') }}
            </div>
        @endif
        @if (session()->has('error'))
            <div class="text-red-500 text-md font-semibold text-center grow mx-4">
                {{ session('error') }}
            </div>
        @endif

        @if ($isSuperadmin)
            <div>
                <button wire:click="addTankModal" class="add-btn text-sm">
                    <i class="fa-solid fa-plus text-sm"></i> Add Tank
                </button>
            </div>
        @endif
    </div>

    <!-- Tank List Table -->
    <div class="py-2">
        <div class="bg-white overflow-hidden sm:rounded-lg">
            <div>
                <table class="w-full border-t">
                    <thead>
                        <tr class="table-header text-xs border-b border-gray-300">
                            <th class="py-2 ps-4 font-semibold text-left">Tank Field</th>
                            <th class="py-2 font-semibold">Tank Name</th>
                            <th class="py-2 font-semibold">Capacity (litres)</th>
                            <th class="py-2 font-semibold">Low %</th>
                            <th class="py-2 font-semibold">Overflow %</th>
                            <th class="py-2 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tanks as $tank)
                            <tr wire:key="tank-{{ $tank->id }}" class="border-b border-gray-300">
                                <td class="text-left text-sm py-3 ps-4">
                                    {{ $tank->field_id === 'd29' ? 'Underground Tank (d29)' : 'Overhead Tank (d30)' }}
                                </td>
                                <td class="text-center text-sm py-3">{{ $tank->tank_name }}</td>
                                <td class="text-center text-sm py-3">{{ \App\Services\NumberFormatterService::formatToIndianFormat($tank->capacity_litres, true) }}</td>
                                <td class="text-center text-sm py-3">{{ $tank->low_alert_percent }}</td>
                                <td class="text-center text-sm py-3">{{ $tank->overflow_alert_percent }}</td>
                                <td class="text-center text-xs py-3 space-x-2">
                                    <div class="inline-block">
                                        <button wire:click="edit({{ $tank->id }})"
                                            class="edit-btn btn-xs">
                                            <i class="fa-regular fa-pen-to-square"></i>Edit
                                        </button>
                                    </div>
                                    @if ($isSuperadmin)
                                        <div class="inline-block">
                                            <button onclick="confirmDeletion({{ $tank->id }})"
                                                class="delete-btn btn-xs">
                                                <i class="fa-regular fa-trash-can"></i>Delete
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-sm text-gray-500">
                                    No water tanks found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <!-- Modal Form -->
                @if ($showModal)
                    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-500/50">
                        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:align-middle sm:max-w-lg sm:w-full sm:h-auto my-1">
                            <div class="bg-white px-6 pt-5 pb-4">

                                <!-- Modal Header -->
                                <div class="flex justify-between items-center">
                                    <h3 class="text-lg leading-6 font-medium text-gray-900">
                                        {{ $tankDbId ? 'Edit Tank' : 'Add Tank' }}
                                    </h3>
                                    <div>
                                        <button wire:click="closeModal"
                                            class="text-red-500 hover:text-red-700 text-lg">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Form -->
                                <form wire:submit.prevent="{{ $tankDbId ? 'update' : 'store' }}">
                                    <!-- Water Tank Radio Buttons -->
                                    <div class="grid grid-cols-3 items-center mt-4">
                                        <label class="place-content-center text-sm text-nowrap">Tank Type</label>
                                        <div class="col-span-2 flex gap-4">
                                            @foreach($tankTypes as $id => $name)
                                                <label class="flex items-center text-sm">
                                                    <input type="radio" wire:model="field_id" value="{{ $id }}" class="mr-2" @disabled($isOwnerOnly && $tankDbId)>
                                                    {{ $name }} ({{ $id }})
                                                </label>
                                            @endforeach
                                        </div>
                                        @if ($errors->has('field_id'))
                                            <div class="col-start-2 col-end-4">
                                                @foreach ($errors->get('field_id') as $error)
                                                    <span class="text-red-500 text-sm block">{{ $error }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Tank Name Input -->
                                    <div class="grid grid-cols-3 items-center mt-4">
                                        <label class="place-content-center text-sm text-nowrap">Tank Name</label>
                                        <input type="text" wire:model="tank_name" class="col-span-2 text-sm input-type" maxlength="16" @readonly($isOwnerOnly && $tankDbId)>
                                        @error('tank_name')
                                            <span class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <!-- Capacity Input -->
                                    <div class="grid grid-cols-3 items-center mt-4">
                                        <label class="place-content-center text-sm text-nowrap">Capacity (Litres)</label>
                                        <input type="number" wire:model="capacity_litres" class="col-span-2 text-sm input-type">
                                        @error('capacity_litres')
                                            <span class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <!-- Low alert threshold -->
                                    <div class="grid grid-cols-3 items-center mt-4">
                                        <label class="place-content-center text-sm text-nowrap">Low alert %</label>
                                        <input type="number" step="0.01" min="1" max="100" wire:model="low_alert_percent" class="col-span-2 text-sm input-type">
                                        @error('low_alert_percent')
                                            <span class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <!-- Overflow alert threshold -->
                                    <div class="grid grid-cols-3 items-center mt-4">
                                        <label class="place-content-center text-sm text-nowrap">Overflow alert %</label>
                                        <input type="number" step="0.01" min="1" max="100" wire:model="overflow_alert_percent" class="col-span-2 text-sm input-type">
                                        @error('overflow_alert_percent')
                                            <span class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <!-- Buttons -->
                                    <div class="flex justify-end space-x-2 mt-4 text-sm">
                                        <button type="submit" class="save-btn">
                                            <i class="fa-solid {{ $editMode ? 'fa-rotate' : 'fa-plus' }}"></i>
                                            {{ $tankDbId ? 'Update Tank' : 'Add Tank' }}
                                        </button>
                                        <button type="button" wire:click="closeModal" class="cancel-btn">Cancel</button>
                                    </div>
                                </form>

                            </div>
                        </div>
                    </div>
                @endif

            </div>
        </div>
    </div>
</div>

<script>
    function confirmDeletion(id) {
        if (confirm('Are you sure you want to delete this tank?')) {
            @this.call('delete', id);
        }
    }
</script>
