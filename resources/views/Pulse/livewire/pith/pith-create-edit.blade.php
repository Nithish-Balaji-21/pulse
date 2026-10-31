<div class="w-full">
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <div class="relative">
                <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                    <x-menu-icon name="pith-production" :size="28" class="text-white" />
                </div>
            </div>
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $productionId ? 'Edit Pith Production' : 'New Pith Production' }}</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ $productionId ? 'Update shift-wise pith load production details' : 'Record a new shift-wise pith load production entry' }}</p>
            </div>
        </div>
    </x-slot>

    @php
        $minDate = $minDate ?? $entryPolicy->minShiftDate();
        $maxDate = $maxDate ?? ($productionId ? $entryPolicy->liveShiftDay() : $entryPolicy->maxCreateShiftDate());
        $timesLocked = !$shiftSelected || !$productionId || $loadsExpected == 0;
        $ebLocked = true;
        $inlineErrorKeys = ['shiftDate', 'shiftId', 'startTime', 'endTime', 'staffId', 'loadProduced', 'loadHours', 'ebUnits', 'ebAmount', 'totalLoadsOffloaded'];
        $hasOtherRecorded = collect($existingProductions)->contains(fn ($p) => data_get($p, 'id') != $productionId);
    @endphp

    <div class="flex flex-col lg:flex-row items-start gap-6 px-2 sm:px-3 lg:px-4 pb-10 max-w-5xl">
        <form wire:submit.prevent="save" class="prod-form w-full min-w-0 lg:max-w-2xl">

            {{-- Errors without a dedicated field (overlap, inventory, …) --}}
            @if (collect($errors->getMessages())->except($inlineErrorKeys)->isNotEmpty())
                <div class="px-6 pt-4">
                    <ul class="text-red-600 text-sm">
                        @foreach (collect($errors->getMessages())->except($inlineErrorKeys) as $key => $messages)
                            @foreach ($messages as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Shift -->
            <div class="prod-section">
                <div class="prod-section-title">Shift</div>
                <div class="prod-grid">
                    <div>
                        <label class="prod-label">Shift Date<span class="text-red-500">*</span>
                            @if ($editing)
                                <span class="label-why">locked while editing</span>
                            @endif
                        </label>
                        <input type="date" wire:model.live="shiftDate" wire:change="updateShiftDate"
                            min="{{ $minDate }}" max="{{ $maxDate }}" onclick="this.showPicker()" required
                            class="input-type w-full text-sm {{ $editing ? 'input-locked' : '' }}" {{ $editing ? 'readonly' : '' }} />
                        @error('shiftDate')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="prod-label">Shift<span class="text-red-500">*</span>
                            @if ($editing)
                                <span class="label-why">locked while editing</span>
                            @endif
                        </label>
                        <select wire:model.live="shiftId" required
                            class="select-type w-full text-sm {{ $editing ? 'input-locked' : '' }}" {{ $editing ? 'disabled' : '' }}>
                            <option value="">Select a shift</option>
                            @foreach ($shifts as $shift)
                                <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                            @endforeach
                        </select>
                        @error('shiftId')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="prod-label">Start Time<span class="text-red-500">*</span></label>
                        <input type="datetime-local" wire:model.live="startTime" onclick="this.showPicker()"
                            min="{{ $shiftDate }}T00:00"
                            max="{{ \Carbon\Carbon::parse($shiftDate)->addDay()->format('Y-m-d') }}T23:59" required
                            {{ $timesLocked ? 'readonly' : '' }}
                            class="input-type w-full text-sm {{ $timesLocked ? 'input-locked' : '' }}" />
                        @error('startTime')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="prod-label">End Time<span class="text-red-500">*</span></label>
                        <input type="datetime-local" wire:model.live="endTime" onclick="this.showPicker()"
                            min="{{ $shiftDate }}T00:00"
                            max="{{ \Carbon\Carbon::parse($shiftDate)->addDay()->format('Y-m-d') }}T23:59" required
                            {{ $timesLocked ? 'readonly' : '' }}
                            class="input-type w-full text-sm {{ $timesLocked ? 'input-locked' : '' }}" />
                        @error('endTime')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="prod-span-2">
                        <label class="prod-label">Staff<span class="text-red-500">*</span></label>
                        <select wire:model="staffId" class="select-type w-full text-sm" required>
                            <option value="">Select a staff</option>
                            @foreach ($staffs as $staff)
                                <option value="{{ $staff->id }}">{{ $staff->staff_name }}</option>
                            @endforeach
                        </select>
                        @error('staffId')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Production -->
            <div class="prod-section">
                <div class="prod-section-title">Production</div>
                <div class="prod-grid">
                    <div>
                        @if ($showActuals)
                            <label class="prod-label">CFT Produced<span class="text-red-500">*</span></label>
                            <input type="number" wire:model.live="loadProduced" class="input-type no-spinner w-full text-sm" min="0" inputmode="numeric" required />
                            @error('loadProduced')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                            @if (!$editing)
                                <p class="text-xs mt-1.5">
                                    <a href="#" wire:click="locationPopUp" class="text-[#83211b] font-semibold hover:underline">Add to Inventory</a>
                                    <span class="prod-chip ml-1">{{ \App\Services\NumberFormatterService::formatCompactNumber($totalLoadsOffloaded ?? 0) }} CFT in inventory</span>
                                </p>
                            @endif
                        @else
                            <label class="prod-label">CFT Estimated</label>
                            <div class="input-type w-full text-sm bg-gray-100 flex items-center">{{ \App\Services\NumberFormatterService::formatCompactNumber($loadsExpected ?? 0) }}</div>
                        @endif
                    </div>
                    <div>
                        <label class="prod-label">Production Hours
                            @if ($pithHrsValidationSetting === 'yes')
                                <span class="text-red-500">*</span>
                            @endif
                        </label>
                        <input type="number" wire:model="loadHours" class="input-type no-spinner w-full text-sm" min="0" max="25" step="0.1" />
                        @error('loadHours')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Electricity -->
            <div class="prod-section">
                <div class="prod-section-title">Electricity</div>
                <div class="prod-grid">
                    <div>
                        <label class="prod-label">EB Units
                            @if ($ebLocked)
                                <span class="label-why">from IoT meter</span>
                            @endif
                        </label>
                        @if ($ebLocked)
                            <div class="input-type input-locked w-full text-sm flex items-center justify-between gap-2">
                                <span>{{ \App\Services\NumberFormatterService::formatToIndianFormat($ebUnits ?: 0) }}</span>
                                <i class="fa-solid fa-lock text-xs opacity-50"></i>
                            </div>
                        @else
                            <input type="number" wire:model.live="ebUnits" class="input-type w-full text-sm" min="0" step="0.01" placeholder="Optional" />
                        @endif
                        @error('ebUnits')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="prod-label">EB Amount (₹)
                            @if ($ebLocked)
                                <span class="label-why">from IoT meter</span>
                            @endif
                        </label>
                        @if ($ebLocked)
                            <div class="input-type input-locked w-full text-sm flex items-center justify-between gap-2">
                                <span>{{ $ebAmount > 99 ? \App\Services\NumberFormatterService::formatToIndianFormat(round($ebAmount), false, 0) : \App\Services\NumberFormatterService::formatToIndianFormat($ebAmount ?: 0) }}</span>
                                <i class="fa-solid fa-lock text-xs opacity-50"></i>
                            </div>
                        @else
                            <input type="number" wire:model="ebAmount" class="input-type w-full text-sm" min="0" step="0.01" placeholder="Optional" />
                        @endif
                        @error('ebAmount')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ((!empty($loadProduced) && !empty($ebUnits) && $loadProduced > 0) || (!empty($loadHours) && !empty($ebUnits) && $loadHours > 0))
                        <div class="prod-span-2 flex flex-wrap gap-1.5">
                            @if (!empty($loadProduced) && !empty($ebUnits) && $loadProduced > 0)
                                <span class="prod-chip">{{ \App\Services\NumberFormatterService::formatToIndianFormat($ebUnits / $loadProduced) }} units / CFT</span>
                            @endif
                            @if (!empty($loadProduced) && !empty($ebAmount) && $loadProduced > 0)
                                <span class="prod-chip">₹ {{ number_format($ebAmount / $loadProduced, 2) }} / CFT</span>
                            @endif
                            @if (!empty($loadHours) && !empty($ebUnits) && $loadHours > 0)
                                <span class="prod-chip">{{ \App\Services\NumberFormatterService::formatToIndianFormat($ebUnits / $loadHours) }} units / hour</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <!-- Comments -->
            <div class="prod-section">
                <div class="prod-section-title">Comments</div>
                <textarea wire:model="comments" rows="3" class="textarea-type w-full text-sm"
                    placeholder="Anything unusual about this shift…"></textarea>
            </div>

            <!-- Actions -->
            <div class="prod-actions text-sm">
                <button type="submit" class="save-btn">Save Production</button>
                <a href="{{ route('pith.index', ['team' => $teamId]) }}" class="cancel-btn">Cancel</a>
            </div>
        </form>

        @if ($shiftDate && $hasOtherRecorded)
            <aside class="w-full lg:w-72 shrink-0">
                <div class="prod-rail-card">
                    <div class="prod-rail-head">
                        <span>Recorded</span>
                        <span class="rail-when">{{ \Carbon\Carbon::parse($shiftDate)->format('d M Y') }}</span>
                    </div>
                    <table class="w-full text-sm" style="font-variant-numeric: tabular-nums;">
                        <thead>
                            <tr class="text-left caps-label">
                                <th class="px-4 pt-2.5 pb-1 font-semibold">Shift</th>
                                <th class="px-4 pt-2.5 pb-1 font-semibold text-right">CFT</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($existingProductions as $production)
                                @php $isCurrent = $editing && data_get($production, 'id') == $productionId; @endphp
                                <tr wire:key="recorded-{{ data_get($production, 'id') }}" class="{{ $isCurrent ? 'bg-[#faf3f2]' : '' }}">
                                    <td class="px-4 py-2 border-t border-gray-100 align-top">
                                        <span class="font-semibold">{{ data_get($production, 'shift.name', data_get($production, 'shift_name', '')) }}</span>
                                        @if ($isCurrent)
                                            <span class="text-xs text-[#861e1a]">· editing</span>
                                        @endif
                                        <div class="text-xs text-gray-500">
                                            {{ \Carbon\Carbon::parse(data_get($production, 'start_time'))->format($dateTimeFormat) }}
                                            –
                                            {{ \Carbon\Carbon::parse(data_get($production, 'end_time'))->roundMinute()->format($dateTimeFormat) }}
                                            · {{ data_get($production, 'staff.staff_name', '') }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 border-t border-gray-100 text-right align-top">
                                        {{ \App\Services\NumberFormatterService::formatCompactNumber(data_get($production, 'cft_produced', data_get($production, 'loads_produced', 0)) ?? 0) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="px-4 py-3 border-t border-gray-100 text-center text-xs text-gray-500">
                                        No production recorded for this date
                                    </td>
                                </tr>
                            @endforelse
                            @if (count($existingProductions) > 0)
                                @php
                                    $dayTotal = collect($existingProductions)->sum(fn ($p) => (float) data_get($p, 'cft_produced', data_get($p, 'loads_produced', 0)));
                                @endphp
                                <tr class="font-semibold bg-[#fbfaf8]">
                                    <td class="px-4 py-2 border-t border-gray-200">Day total</td>
                                    <td class="px-4 py-2 border-t border-gray-200 text-right">{{ \App\Services\NumberFormatterService::formatCompactNumber($dayTotal) }}</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </aside>
        @endif
    </div>

    @if ($inventoryPopUp)
        @include('pulse::livewire.pith.partials.inventory-modal', [
            'modalTitle' => 'Add to Inventory',
            'closeAction' => 'locationPopUp',
            'errorKey' => 'totalLoadsOffloaded',
            'totalCft' => $loadProduced,
        ])
    @endif
</div>
