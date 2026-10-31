<!-- staff-manager.blade.php -->
<div>
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">
            {{ __('People: Staff Workers') }}
        </h2>
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
        <div>
            <button wire:click="openForm" class="add-btn text-sm">
                <i class="fa-solid fa-plus text-sm"></i> Add Staff
            </button>
        </div>
    </div>
    <div class="py-2">
        <div class="">
            <div class="bg-white overflow-hidden sm:rounded-lg">
                <div>
                    <!-- Staff List -->
                    <div>
                        <table class="w-full border-t">
                            <thead>
                                <tr class="table-header text-xs border-b border-gray-300">
                                    <th class="py-2 pe-1 font-semibold ps-4 text-left">Name</th>
                                    <th class="py-2 pe-1 font-semibold">Salary / hr</th>
                                    <th class="py-2 pe-1 font-semibold">Status</th>
                                    <th class="py-2 pe-1 font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="tabular-nums">
                                @foreach ($staffs as $staff)
                                    <tr class="border-b border-gray-300">
                                        <td class="text-left text-sm py-3 ps-4">{{ $staff->staff_name }}</td>
                                        <td class="text-center text-sm py-3">₹ {{ $staff->salary_per_hr }}</td>
                                        <td
                                            class="text-center text-sm py-3 {{ $staff->is_active ? 'Active' : 'text-red-500' }}">
                                            {{ $staff->is_active ? 'Active' : 'Inactive' }}</td>
                                        <td class="text-center text-xs py-3 space-x-2">
                                            <div class="w-24 text-right inline-block">
                                                <button wire:click="confirmToggle({{ $staff->id }})"
                                                    class="px-2 py-1 text-white font-semibold rounded text-nowrap text-xs
                                                    {{ $staff->is_active ? 'bg-[#DC2626] hover:bg-[#B91C1C]' : 'bg-[#15803d] hover:bg-[#166534]' }}">
                                                    <i
                                                        class="fa-solid {{ $staff->is_active ? 'fa-circle-xmark' : 'fa-circle-check' }}"></i>
                                                    {{ $staff->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </div>
                                            <div class="w-fit inline-block">
                                                <button wire:click="openForm({{ $staff->id }})"
                                                    class="edit-btn btn-xs">
                                                    <i class="fa-regular fa-pen-to-square"></i>Edit
                                                </button>
                                            </div>
                                            <div class="w-fit inline-block">
                                                <button onclick="confirmDeletion({{ $staff->id }})"
                                                    class="delete-btn btn-xs">
                                                    <i class="fa-regular fa-trash-can"></i>Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <!-- Modal Form -->
                    @if ($showModal)
                        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-500/50">
                            <div
                                class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:align-middle sm:max-w-lg sm:w-full sm:h-auto my-1">
                                <div class="bg-white px-6 pt-5 pb-4">
                                    <div class="flex justify-between items-center">
                                        <h3 class="text-lg leading-6 font-medium text-gray-900">
                                            {{ $staff_id ? 'Edit Staff' : 'Add Staff' }}
                                        </h3>
                                        <div>
                                            <button wire:click="$set('showModal', false)"
                                                class="text-red-500 hover:text-red-700 text-lg">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex" >
                                        <div class="w-1/3"></div>
                                        @if (session()->has('error'))
                                            <div class="w-2/3 text-red-500 text-md place-content-center text-left font-semibold">{{ session('error') }}</div>
                                        @endif
                                    </div>
                                    <!-- Form -->
                                    <form wire:submit.prevent="{{ $staff_id ? 'update' : 'store' }}">
                                        <div class="grid grid-cols-3 items-center mt-4">
                                            <label for="shiftName"
                                                class="place-content-center text-sm text-nowrap">Staff
                                                Name</label>
                                            <input type="text" id="shiftName" wire:model="staff_name"
                                                class="col-span-2 text-sm input-type">
                                            @error('staff_name')
                                            <span class="text-red-500 text-sm col-start-2 col-end-4">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="flex items-center mt-4">
                                            <label for="shiftName"
                                                class="w-1/3 place-content-center text-sm text-nowrap">Salary /
                                                hr</label>
                                            <input type="number" id="shiftName" wire:model="salary_per_hr"
                                                class="w-2/3 text-sm input-type">
                                        </div>
                                        <div class="flex justify-end space-x-2 mt-4 text-sm">
                                            <button type="submit" class="add-btn">
                                                <i class="fa-solid {{ $editMode ? 'fa-rotate' : 'fa-plus' }}"></i>
                                                {{ $editMode ? 'Update Staff' : 'Add Staff' }}
                                            </button>
                                            <button type="button" wire:click="closeForm" class="cancel-btn">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif
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
                                    {{ __('Are you sure you want to change the status of this staff?') }}
                                </div>
                                <div class="flex justify-end gap-3">
                                    <button type="button" wire:click="cancelToggle" class="cancel-btn">
                                        {{ __('Cancel') }}
                                    </button>
                                    <button type="button" wire:click="toggleStatus" wire:loading.attr="disabled" class="add-btn">
                                        {{ __('Yes, Change') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    function confirmDeletion(id) {
        if (confirm('Are you sure you want to delete this staff?')) {
            @this.call('delete', id);
        }
    }
</script>
