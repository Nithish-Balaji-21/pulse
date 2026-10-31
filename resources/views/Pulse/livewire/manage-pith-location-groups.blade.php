<div>
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">
            {{ __('Pith Load Settings: Location Groups') }}
        </h2>
        @if (session()->has('success'))
            <div class="text-green-500 text-md font-semibold text-center grow mx-4">{{ session('success') }}</div>
        @endif
        @if (session()->has('error'))
            <div class="text-red-500 text-md font-semibold text-center grow mx-4">{{ session('error') }}</div>
        @endif
        <div>
            <button wire:click="openGroupModal" class="add-btn text-sm">
                <i class="fa-solid fa-plus text-sm"></i> Add Group
            </button>
        </div>
    </div>
    <div class="py-2 bg-white overflow-hidden sm:rounded-lg">
        <!-- Modal Popup -->
        @if ($isModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-5">
                <div class="bg-white sm:w-full md:w-2/3 lg:w-1/3 h-auto p-4 rounded-lg overflow-y-auto">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-lg font-medium">{{ $editing ? 'Edit Group' : 'Add Group' }}</h3>
                        <button wire:click="closeGroupModal" class="text-red-500 hover:text-red-700 text-lg">
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
                            <div class="flex items-center">
                                <label for="groupName"
                                    class="w-1/3 place-content-center text-sm text-nowrap">Group Name</label>
                                <input type="text" id="groupName" wire:model="groupName"
                                    class="w-2/3 text-sm input-type" maxlength="50">
                                @error('groupName')
                                    <span class="text-red-500 text-sm">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="flex items-center">
                                <label for="displayOrder"
                                    class="w-1/3 place-content-center text-sm text-nowrap">Display Order</label>
                                <input type="number" id="displayOrder" wire:model="displayOrder"
                                    min="0" step="1" placeholder="0"
                                    class="w-2/3 text-sm input-type">
                            </div>
                            <p class="text-xs text-gray-500 ps-[33%]">Groups are listed by this number first, then alphabetically. Leave empty to sort by name.</p>

                            <div class="flex justify-end space-x-2 mt-4 text-sm">
                                <button type="submit" class="save-btn">
                                    <i class="fa-solid {{ $editing ? 'fa-rotate' : 'fa-plus' }}"></i>
                                    {{ $editing ? 'Update Group' : 'Add Group' }}
                                </button>
                                <button type="button" wire:click="closeGroupModal"
                                    class="cancel-btn">Cancel</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endif
        <!-- Groups Table -->
        <div class="overflow-x-auto overflow-y-auto">
            <table class="w-full border-t">
                <thead>
                    <tr class="table-header text-xs border-b border-gray-300">
                        <th class="py-2 pe-1 font-semibold ps-4 text-left">Group Name</th>
                        <th class="py-2 pe-1 font-semibold text-center">Display Order</th>
                        <th class="py-2 pe-1 font-semibold text-center">Locations</th>
                        <th class="py-2 pe-1 font-semibold">Status</th>
                        <th class="py-2 pe-1 font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($groups as $group)
                        @php
                            $activeCount = (int) ($group->active_pith_locations_count ?? 0);
                            $totalCount = (int) ($group->pith_locations_count ?? 0);
                            $toggleBlocked = $group->is_active && $activeCount > 0;
                        @endphp
                        <tr class="text-xs border-b border-gray-300">
                            <td class="text-left text-sm py-3 ps-4 capitalize">{{ $group->name }}</td>
                            <td class="text-center text-sm py-3">
                                {{ ($group->display_order ?? 0) > 0 ? $group->display_order : '-' }}
                            </td>
                            <td class="text-center text-sm py-3">
                                {{ $totalCount }}
                                @if ($totalCount > 0)
                                    <span class="text-gray-500 tabular-nums">({{ $activeCount }} active)</span>
                                @endif
                            </td>
                            <td class="text-center text-sm py-3 capitalize {{ $group->is_active ? 'text-green-500' : 'text-red-500' }}">
                                {{ $group->is_active ? 'Active' : 'Inactive' }}
                            </td>
                            <td class="text-center text-xs py-3 space-x-2">
                                <div class="w-24 text-right inline-block">
                                    <button wire:click="confirmToggle({{ $group->id }})"
                                        @if ($toggleBlocked) title="Reassign or deactivate its locations first" @endif
                                        class="px-2 py-1 text-white font-semibold rounded text-nowrap text-xs
                                        {{ $group->is_active ? 'bg-[#DC2626] hover:bg-[#B91C1C]' : 'bg-[#15803d] hover:bg-[#166534]' }}
                                        {{ $toggleBlocked ? 'opacity-50' : '' }}">
                                        <i class="fa-solid {{ $group->is_active ? 'fa-circle-xmark' : 'fa-circle-check' }}"></i>
                                        {{ $group->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </div>
                                <div class="w-fit inline-block">
                                    <button wire:click="edit({{ $group->id }})"
                                        class="edit-btn btn-xs">
                                        <i class="fa-regular fa-pen-to-square"></i>Edit
                                    </button>
                                </div>
                                <div class="w-fit inline-block">
                                    <button onclick="confirmGroupDeletion({{ $group->id }})"
                                        class="delete-btn btn-xs">
                                        <i class="fa-regular fa-trash-can"></i>Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-sm py-6 text-gray-500">
                                No groups yet. Add a group, then assign locations to it from the Locations page.
                            </td>
                        </tr>
                    @endforelse
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
                        {{ __('Are you sure you want to change the status of this group?') }}
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
    function confirmGroupDeletion(id) {
        if (confirm('Are you sure you want to delete this group?')) {
            @this.call('delete', id);
        }
    }
</script>
