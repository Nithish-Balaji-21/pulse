<div x-data="{ open: @entangle('showModal') }">
    @php
        $isSuperadmin = Auth::user()->isSuperadmin();
    @endphp

    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">{{ __('Growbag Machines') }}</h2>
        @can('manage-factory-configuration')
            @if ($isSuperadmin)
                <button wire:click="openCreateModal" class="add-btn text-sm">
                    <i class="fa-solid fa-plus text-sm"></i> Add Machine
                </button>
            @endif
        @endcan
    </div>

    {{-- Flash Message --}}
    @if (session('message'))
        <div id="flash-message"
            class="z-50 bg-[#e2faf2] border-2 mt-4 border-white text-gray-600 px-4 py-3 rounded-xl fixed top-4 left-1/2 transform -translate-x-1/2 shadow-md">
            <i class="p-2 text-white bg-green-600 rounded-lg fa-solid fa-circle-check"></i>
            <span class="ml-1 font-semibold">{{ session('message') }}</span>
        </div>
    @endif

    {{-- Modal --}}
    @if ($showModal)
        <div class="fixed z-50 inset-0 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 opacity-75"></div>
                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-medium">
                            {{ $editMode ? 'Edit Machine' : 'Add Machine' }}
                        </h3>
                        <button wire:click="closeModal" class="text-red-500 hover:text-red-700">
                            <i class="fa-solid fa-xmark text-lg"></i>
                        </button>
                    </div>

                    <form wire:submit.prevent="save" class="space-y-4">
                        @if ($isSuperadmin)
                        <div class="grid grid-cols-3 items-center gap-2">
                            <label class="text-sm">Field ID</label>
                            <div class="col-span-2">
                                <input type="text" wire:model="field_id"
                                    placeholder="e.g. d45"
                                    class="w-full input-type text-sm">
                                @error('field_id') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        @endif
                        <div class="grid grid-cols-3 items-center gap-2">
                            <label class="text-sm">Machine Name</label>
                            <div class="col-span-2">
                                <input type="text" wire:model="machine_name"
                                    placeholder="e.g. M1"
                                    class="w-full input-type text-sm">
                                @error('machine_name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="grid grid-cols-3 items-center gap-2">
                            <label class="text-sm">Bags per punch</label>
                            <div class="col-span-2">
                                <input type="number" wire:model="bags_per_punch"
                                    min="1" max="99"
                                    class="w-full input-type text-sm">
                                @error('bags_per_punch') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        @if ($isSuperadmin)
                        <div class="grid grid-cols-3 items-center gap-2">
                            <label class="text-sm">Status</label>
                            <div class="col-span-2">
                                <select wire:model="is_active" class="w-full select-type text-sm">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        @endif

                        <div class="flex justify-end gap-2 mt-4">
                            <button type="submit" class="save-btn text-sm">
                                <i class="fa-solid {{ $editMode ? 'fa-rotate' : 'fa-plus' }}"></i>
                                {{ $editMode ? 'Update' : 'Add' }}
                            </button>
                            <button type="button" wire:click="closeModal" class="cancel-btn text-sm">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Table --}}
    <div class="py-2 px-4">
        <table class="w-full border-t">
            <thead>
                <tr class="table-header text-xs border-b border-gray-300">
                    <th class="py-2 px-4 text-left">Name</th>
                    @if ($isSuperadmin)
                        <th class="py-2 px-4 text-left">Field ID</th>
                    @endif
                    <th class="py-2 px-4 text-center">Bags/punch</th>
                    <th class="py-2 px-4 text-center">Status</th>
                    <th class="py-2 px-4 text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($machines as $machine)
                    <tr class="border-b border-gray-300 hover:bg-gray-50">
                        <td class="py-3 px-4 text-sm text-gray-900">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                                {{ $machine->machine_name }}
                            </span>
                        </td>
                        @if ($isSuperadmin)
                            <td class="py-3 px-4 text-sm tabular-nums text-gray-900">{{ $machine->field_id }}</td>
                        @endif
                        <td class="py-3 px-4 text-sm text-center">
                            <span class="tabular-nums">×{{ $machine->bags_per_punch }}</span>
                            <span class="text-gray-500 text-xs ml-1">bags/punch</span>
                        </td>
                        <td class="py-3 px-4 text-sm text-center">
                            @if ($machine->is_active)
                                <span class="status-badge status-on"><i class="fa-solid fa-circle-check text-[9px]"></i>Active</span>
                            @else
                                <span class="status-badge status-off"><i class="fa-solid fa-circle-minus text-[9px]"></i>Inactive</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-sm text-center">
                            @can('manage-factory-configuration')
                                <button wire:click="openEditModal({{ $machine->id }})" class="edit-btn btn-xs mr-1">
                                    <i class="fa-regular fa-pen-to-square"></i>Edit
                                </button>
                                @if ($isSuperadmin)
                                    <button wire:click="confirmDeletion({{ $machine->id }})" class="delete-btn btn-xs">
                                        <i class="fa-regular fa-trash-can"></i>Delete
                                    </button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Delete Confirmation --}}
    @if ($showDeleteConfirmationDialog)
        <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center">
            <div class="bg-white p-6 rounded-lg shadow-lg w-96">
                <h2 class="mb-2 font-semibold text-md">Confirm Deletion</h2>
                <p class="text-sm">Are you sure you want to delete this machine? This will affect future cron calculations.</p>
                <div class="flex justify-end gap-4 mt-4">
                    <button class="delete-btn" wire:click="deleteMachine">Confirm</button>
                    <button class="cancel-btn" wire:click="closeDeleteModal">Cancel</button>
                </div>
            </div>
        </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const flashMessage = document.getElementById('flash-message');
            if (flashMessage) {
                setTimeout(() => {
                    flashMessage.classList.add('opacity-0');
                    setTimeout(() => flashMessage.remove(), 500);
                }, 3000);
            }
        });
    </script>
</div>
