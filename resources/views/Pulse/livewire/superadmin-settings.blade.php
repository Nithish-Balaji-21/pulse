<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl leading-tight">
            {{ __('Superadmin Settings') }}
        </h2>
    </x-slot>

    <div class="mb-4">
        <a href="{{ route('superadmin.monitoring', ['team' => request()->route('team')]) }}"
           class="inline-flex items-center gap-2 rounded-lg bg-[#83211b] px-4 py-2 font-semibold text-white hover:bg-[#6b1b16] focus:outline-none focus:ring-2 focus:ring-[#83211b] focus:ring-offset-2">
            <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
            {{ __('DevOps Tools') }}
        </a>
    </div>

    @if (session()->has('success'))
        <div class="mb-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="mb-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    @if (!$isTableAvailable)
        <div class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            <div class="font-semibold">`pulse_superadmin_settings` table is not available.</div>
            <div class="mt-1 break-all">{{ $tableError }}</div>
        </div>
    @else
        <div class="rounded border border-gray-200 bg-white">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="table-header text-xs border-b border-gray-300">
                            <th class="px-4 py-2 text-left font-semibold">ID</th>
                            <th class="px-4 py-2 text-left font-semibold">Setting Key</th>
                            @if ($hasSettingTypeColumn)
                                <th class="px-4 py-2 text-left font-semibold">Setting Type</th>
                            @endif
                            <th class="px-4 py-2 text-left font-semibold">Setting Value</th>
                            @if ($hasRemarksColumn)
                                <th class="px-4 py-2 text-left font-semibold">Remarks</th>
                            @endif
                            <th class="px-4 py-2 text-left font-semibold">Active</th>
                            @if ($hasCreatedAtColumn)
                                <th class="px-4 py-2 text-left font-semibold">Created At</th>
                            @endif
                            @if ($hasUpdatedAtColumn)
                                <th class="px-4 py-2 text-left font-semibold">Updated At</th>
                            @endif
                            <th class="px-4 py-2 text-left font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($coiiotSettings as $row)
                            <tr class="border-b border-gray-200">
                                <td class="px-4 py-2 text-sm">{{ $row->id ?? '-' }}</td>
                                <td class="px-4 py-2 text-sm">{{ $row->setting_key ?? '-' }}</td>
                                @if ($hasSettingTypeColumn)
                                    <td class="px-4 py-2 text-sm">{{ $row->setting_type ?? '-' }}</td>
                                @endif
                                <td class="px-4 py-2 text-sm break-all">{{ $row->setting_value ?? '-' }}</td>
                                @if ($hasRemarksColumn)
                                    <td class="px-4 py-2 text-sm">{{ $row->remarks ?? '-' }}</td>
                                @endif
                                <td class="px-4 py-2 text-sm">{{ (int) ($row->is_active ?? 0) === 1 ? 'Yes' : 'No' }}</td>
                                @if ($hasCreatedAtColumn)
                                    <td class="px-4 py-2 text-sm">{{ $row->created_at ?? '-' }}</td>
                                @endif
                                @if ($hasUpdatedAtColumn)
                                    <td class="px-4 py-2 text-sm">{{ $row->updated_at ?? '-' }}</td>
                                @endif
                                <td class="px-4 py-2 text-sm">
                                    <button
                                        type="button"
                                        wire:click="editSetting({{ (int) $row->id }})"
                                        class="rounded bg-blue-500 px-2 py-1 text-xs font-semibold text-white hover:bg-blue-600"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-3 text-center text-sm text-gray-500">No rows found in pulse_superadmin_settings.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($isEditModalOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-500/50">
                <div class="w-11/12 max-w-2xl rounded-lg bg-white p-6 shadow-lg">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold">Edit Admin Setting</h3>
                        <button type="button" wire:click="closeEditModal" class="text-lg text-red-500 hover:text-red-700">
                            <i class="fa-solid fa-times"></i>
                        </button>
                    </div>

                    <form wire:submit.prevent="updateSetting" class="space-y-3">
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm">Setting Key</label>
                                <input type="text" wire:model.defer="editSettingKey" class="input-type w-full cursor-not-allowed bg-gray-100 text-sm" readonly />
                                @error('editSettingKey') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>

                            @if ($hasSettingTypeColumn)
                                <div>
                                    <label class="mb-1 block text-sm">Setting Type</label>
                                    <input type="text" wire:model.defer="editSettingType" class="input-type w-full cursor-not-allowed bg-gray-100 text-sm" readonly />
                                    @error('editSettingType') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                            @endif

                            <div class="md:col-span-2">
                                <label class="mb-1 block text-sm">Setting Value</label>
                                <textarea wire:model.defer="editSettingValue" rows="3" class="input-type w-full text-sm"></textarea>
                                @error('editSettingValue') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>

                            @if ($hasRemarksColumn)
                                <div class="md:col-span-2">
                                    <label class="mb-1 block text-sm">Remarks</label>
                                    <input type="text" wire:model.defer="editRemarks" class="input-type w-full text-sm" />
                                    @error('editRemarks') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                            @endif

                            <div>
                                <label class="mb-1 block text-sm">Is Active</label>
                                <select wire:model.defer="editIsActive" class="input-type w-full text-sm">
                                    <option value="1">Yes</option>
                                    <option value="0">No</option>
                                </select>
                                @error('editIsActive') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="mt-4 flex justify-end gap-2 text-sm">
                            <button type="submit" class="save-btn">Update</button>
                            <button type="button" wire:click="closeEditModal" class="cancel-btn">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endif
</div>
