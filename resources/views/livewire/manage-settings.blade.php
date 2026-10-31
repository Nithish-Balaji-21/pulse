<div class="">

    <x-slot name="header">
        <h2 class="font-semibold text-xl leading-tight">
            {{ __('Factory Settings: Customizations') }}
        </h2>
    </x-slot>

    <table class="w-full border-t">
        <thead class="sticky top-[4px]">
            <tr class="table-header text-xs border-b border-gray-300">
                <th class="px-4 py-2 text-left font-semibold">Field</th>
                <th class="px-4 py-2 text-left font-semibold">Value</th>
                <th class="px-4 py-2 text-left font-semibold">Options</th>
                <th class="px-4 py-2 text-left font-semibold">Update By</th>
                <th class="px-4 py-2 text-left font-semibold">Update At</th>
                <th class="px-4 py-2 font-semibold">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($settings as $index => $setting)
                <tr class="border-b border-gray-300">
                    <td class="px-4 py-2 text-sm">{{ $setting->text }}</td>
                    <td class="px-4 py-2 text-sm">
                        @if ($this->isEntryPolicyKey($setting->key ?? null))
                            {{ \App\Support\Pulse\ProductionEntryPolicyKeys::formatDaysLabel($setting->value ?? null) }}
                        @else
                            {{ $setting->value }}
                        @endif
                    </td>
                    <td class="px-4 py-2 text-sm">
                        @if ($this->isEntryPolicyKey($setting->key ?? null))
                            -
                        @else
                            {{ $setting->options }}
                        @endif
                    </td>
                    <td class="px-4 py-2 text-sm">{{ $setting->user->name ?? '-' }}</td>
                    <td class="px-4 py-2 text-sm">
                        {{ \Carbon\Carbon::parse($setting->updated_at)->format('d/m/Y h:i A') }}
                    </td>
                    <td class="px-4 py-2 text-xs">
                        <button wire:click="edit({{ $setting->id }})"
                            class="edit-btn btn-xs">
                            <i class="fa-solid fa-edit"></i> Edit
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-2 text-center text-gray-500"></td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($isModelOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
             x-data
             x-on:keydown.escape.window="$wire.closeModal()">
            <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4 bg-slate-50/50">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Edit Setting</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Update customization configuration value.</p>
                    </div>
                    <button type="button" wire:click="closeModal" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 transition-colors">
                        <i class="fa-solid fa-times text-sm"></i>
                    </button>
                </div>

                <form wire:submit.prevent="update">
                    <div class="px-5 py-5 space-y-4">
                        <div>
                            <label for="settingValue" class="block text-xs font-normal text-gray-700 leading-normal mb-2">
                                {{ $text }}
                            </label>

                            @if ($this->isEntryPolicyKey($settingKey))
                                <select wire:model="value" id="settingValue" class="select-type w-full text-sm font-normal text-gray-800" required>
                                    <option value="">Select value</option>
                                    @foreach (\App\Support\Pulse\ProductionEntryPolicyKeys::dayOptionsList() as $day)
                                        <option value="{{ $day }}">{{ $day }}</option>
                                    @endforeach
                                </select>
                            @else
                                @if (count($options) > 0)
                                    <select wire:model="value" id="settingValue" class="select-type w-full text-sm font-normal text-gray-800">
                                        <option value="">Select Option</option>
                                        @foreach ($options as $option)
                                            <option value="{{ $option }}">{{ $option }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input type="text" wire:model="value" id="settingValue" class="input-type w-full text-sm font-normal text-gray-800" placeholder="Enter Value">
                                @endif
                            @endif

                            @error('value')
                                <p class="text-rose-500 text-xs mt-1.5 font-medium">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end gap-2.5 border-t border-gray-200 px-5 py-3.5 bg-gray-50/50">
                        <button type="button" wire:click="closeModal" class="cancel-btn text-xs py-1.5 px-4">Cancel</button>
                        <button type="submit" class="add-btn text-xs py-1.5 px-4">Update</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
