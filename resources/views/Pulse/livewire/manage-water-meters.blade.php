<div>
    @php
        $isSuperadmin = Auth::user()->isSuperadmin();
    @endphp

    <!-- Header -->
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">{{ __('IoT Water Meters') }}</h2>

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
                <button wire:click="addMeterModal" class="add-btn text-sm">
                    <i class="fa-solid fa-plus text-sm"></i> Add Meter
                </button>
            </div>
        @endif
    </div>

    <!-- Meter List Table -->
    <div class="py-2">
        <div class="bg-white overflow-hidden sm:rounded-lg">
            <div>
                <table class="w-full border-t">
                    <thead>
                        <tr class="table-header text-xs border-b border-gray-300">
                            @if ($isSuperadmin)
                                <th class="py-2 ps-4 font-semibold text-left">Device ID</th>
                            @endif
                            <th class="py-2 {{ $isSuperadmin ? '' : 'ps-4 text-left' }} font-semibold">Meter Type</th>
                            <th class="py-2 font-semibold">Consumption Name</th>
                            <th class="py-2 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($meters as $meter)
                            <tr wire:key="meter-{{ $meter->id }}" class="border-b border-gray-300">
                                @if ($isSuperadmin)
                                    <td class="text-left text-sm py-3 ps-4">{{ $meter->device_id }}</td>
                                @endif
                                <td class="text-center text-sm py-3 {{ $isSuperadmin ? '' : 'ps-4 text-left' }}">
                                    {{ $meter->field_id === 'd27' ? 'Total Water (d27)' : 'Husk Water (d16)' }}
                                </td>
                                <td class="text-center text-sm py-3">{{ $meter->consumption_name }}</td>
                                <td class="text-center text-xs py-3 space-x-2">
                                    <div class="inline-block">
                                        <button wire:click="edit({{ $meter->id }})"
                                            class="edit-btn btn-xs">
                                            <i class="fa-regular fa-pen-to-square"></i>Edit
                                        </button>
                                    </div>
                                    @if ($isSuperadmin)
                                        <div class="inline-block">
                                            <button onclick="confirmDeletion({{ $meter->id }})"
                                                class="delete-btn btn-xs">
                                                <i class="fa-regular fa-trash-can"></i>Delete
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isSuperadmin ? 4 : 3 }}" class="text-center py-4 text-sm text-gray-500">
                                    No water meters found.
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
                                        {{ $meterDbId ? 'Edit Meter' : 'Add Meter' }}
                                    </h3>
                                    <div>
                                        <button wire:click="closeModal"
                                            class="text-red-500 hover:text-red-700 text-lg">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Form -->
                                <form wire:submit.prevent="{{ $meterDbId ? 'update' : 'store' }}">
                                    <!-- Device ID: Superadmin only, always readonly -->
                                    @if ($isSuperadmin)
                                    <div class="grid grid-cols-3 items-center mt-4">
                                        <label class="place-content-center text-sm text-nowrap">Device ID</label>
                                        <input type="text" wire:model="device_id" readonly
                                            class="col-span-2 text-sm input-type bg-gray-100 cursor-not-allowed">
                                        @error('device_id')
                                            <span class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    @endif

                                    <!-- Meter Type: editable on create only; locked on edit -->
                                    <div class="grid grid-cols-3 items-center mt-4">
                                        <label class="place-content-center text-sm text-nowrap">Meter Type</label>
                                        <div class="col-span-2 flex gap-4">
                                            @if ($meterDbId)
                                                <div class="text-sm text-gray-700 py-1">
                                                    {{ ($field_id === 'd27' ? 'Total Water' : 'Husk Water') }} ({{ $field_id }})
                                                </div>
                                            @else
                                                @foreach($meterTypes as $id => $name)
                                                    <label class="flex items-center text-sm">
                                                        <input type="radio" wire:model="field_id" value="{{ $id }}" class="mr-2">
                                                        {{ $name }} ({{ $id }})
                                                    </label>
                                                @endforeach
                                            @endif
                                        </div>
                                        @if ($errors->has('field_id'))
                                            <div class="col-start-2 col-end-4">
                                                @foreach ($errors->get('field_id') as $error)
                                                    <span class="text-red-500 text-sm block">{{ $error }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Consumption Name Input -->
                                    <div class="grid grid-cols-3 items-center mt-4">
                                        <label class="place-content-center text-sm text-nowrap">Consumption Name</label>
                                        <input type="text" wire:model="consumption_name" class="col-span-2 text-sm input-type">
                                        @error('consumption_name')
                                            <span class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <!-- Buttons -->
                                    <div class="flex justify-end space-x-2 mt-4 text-sm">
                                        <button type="submit" class="save-btn">
                                            <i class="fa-solid {{ $editMode ? 'fa-rotate' : 'fa-plus' }}"></i>
                                            {{ $meterDbId ? 'Update Meter' : 'Add Meter' }}
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
        if (confirm('Are you sure you want to delete this meter?')) {
            @this.call('delete', id);
        }
    }
</script>
