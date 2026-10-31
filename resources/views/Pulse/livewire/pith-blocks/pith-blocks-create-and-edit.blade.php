<div class="w-full">
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <div class="relative">
                <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                    <x-menu-icon name="pith-blocks" :size="28" class="text-white" />
                </div>
            </div>
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $productionId ? 'Edit Pith Blocks Production' : 'New Pith Blocks Production' }}</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ $productionId ? 'Update daily pith block production details' : 'Record a new daily pith block production entry' }}</p>
            </div>
        </div>
    </x-slot>

    @php
        $minDate = $minDate ?? $entryPolicy->minShiftDate();
        $maxDate = $maxDate ?? ($productionId ? $entryPolicy->liveShiftDay() : $entryPolicy->maxCreateShiftDate());
    @endphp

    <div class="px-2 sm:px-3 lg:px-4 pb-10">
        <form wire:submit.prevent="save" class="prod-form w-full max-w-2xl">

            <!-- Production day -->
            <div class="prod-section">
                <div class="prod-section-title">Production day</div>
                @if ($errors->has('duplicate'))
                    <p class="text-red-600 text-sm mb-3">{{ $errors->first('duplicate') }}</p>
                @endif
                <div class="prod-grid">
                    <div class="prod-span-2">
                        <label class="prod-label">Date<span class="text-red-500">*</span></label>
                        <input type="date" wire:model.live="date" min="{{ $minDate }}" max="{{ $maxDate }}"
                            onclick="this.showPicker()" required class="input-type w-full text-sm" />
                        @error('date')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="prod-span-2">
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
                            <label class="prod-label">Blocks Produced<span class="text-red-500">*</span></label>
                            <input type="number" wire:model="blocksProduced" class="input-type w-full text-sm" min="0" required />
                            @if ($productionId && (int) $blocksExpected > 0)
                                <p class="text-xs text-gray-500 mt-1">IoT estimate: {{ $blocksExpected }}</p>
                            @endif
                            @error('blocksProduced')
                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                            @enderror
                        @else
                            <label class="prod-label">Blocks Estimated</label>
                            <div class="input-type w-full text-sm bg-gray-100 flex items-center">{{ $blocksExpected ?? 0 }}</div>
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
                <a href="{{ route('pith-blocks.index', ['team' => $teamId]) }}" class="cancel-btn">Cancel</a>
            </div>
        </form>
    </div>
</div>
