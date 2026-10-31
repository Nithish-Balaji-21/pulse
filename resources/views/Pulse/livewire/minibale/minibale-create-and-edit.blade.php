<div class="w-full">
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <div class="relative">
                <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                    <x-menu-icon name="fibre-bales" :size="28" class="text-white" />
                </div>
            </div>
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $productionId ? 'Edit Fibre Production' : 'New Fibre Production' }}</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ $productionId ? 'Update shift-wise fibre bale production details' : 'Record a new shift-wise fibre bale production entry' }}</p>
            </div>
        </div>
    </x-slot>

    @php
        $hasMachines = count($machines) > 1;
        $machinePalette = config('colors.minibales_charts.machines', []);
        $machineColorMap = [];
        foreach ($machines as $i => $m) {
            $machineColorMap[$m->id] = $machinePalette[$i] ?? '#6b7280';
        }
    @endphp

    <div class="px-2 sm:px-3 lg:px-4 pb-10">
        <form wire:submit.prevent="save" class="prod-form w-full max-w-2xl">

            <!-- Shift -->
            <div class="prod-section">
                <div class="prod-section-title">Shift</div>
                @if ($errors->has('duplicate'))
                    <p class="text-red-600 text-sm mb-3">{{ $errors->first('duplicate') }}</p>
                @endif
                <div class="prod-grid">
                    <div>
                        <label class="prod-label">Date<span class="text-red-500">*</span></label>
                        <input type="date" wire:model.live="date" min="{{ $minDate }}" max="{{ $maxDate }}"
                            onclick="this.showPicker()" required class="input-type w-full text-sm" />
                        @error('date')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ($hasMachines)
                        <div>
                            <label class="prod-label">Machine<span class="text-red-500">*</span></label>
                            <select wire:model.live="machineId" class="select-type w-full text-sm" required>
                                <option value="">Select a machine</option>
                                @foreach ($machines as $machine)
                                    <option value="{{ $machine->id }}">{{ $machine->machine_name }}</option>
                                @endforeach
                            </select>
                            @if ($machineId && isset($machineColorMap[$machineId]))
                                <div class="flex items-center gap-1.5 mt-1">
                                    <span style="width:8px;height:8px;border-radius:50%;background-color:{{ $machineColorMap[$machineId] }};display:inline-block;flex-shrink:0;"></span>
                                    <span class="text-xs font-semibold" style="color:{{ $machineColorMap[$machineId] }}">{{ $machineName }}</span>
                                </div>
                            @endif
                            @error('machineId')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                    <div class="{{ $hasMachines ? '' : 'prod-span-2' }}">
                        <label class="prod-label">Staff</label>
                        <select wire:model="staffId" class="select-type w-full text-sm">
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
                            <label class="prod-label">Bales Produced<span class="text-red-500">*</span></label>
                            <input type="number" wire:model="balesProduced" class="input-type w-full text-sm" min="0" required />
                            @error('balesProduced')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        @else
                            <label class="prod-label">Bales Estimated</label>
                            <div class="input-type w-full text-sm bg-gray-100 flex items-center">{{ $balesExpected ?? 0 }}</div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Comments -->
            <div class="prod-section">
                <div class="prod-section-title">Comments</div>
                <textarea wire:model="comments" rows="3" class="textarea-type w-full text-sm"></textarea>
            </div>

            <!-- Actions -->
            <div class="prod-actions text-sm">
                <button type="submit" class="save-btn">Save Production</button>
                <a href="{{ route('fibre.index', ['team' => $teamId]) }}" class="cancel-btn">Cancel</a>
            </div>
        </form>
    </div>
</div>
