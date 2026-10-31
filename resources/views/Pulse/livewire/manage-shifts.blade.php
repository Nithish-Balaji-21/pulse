<div>
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">
            {{ __('Factory Settings: Shift Schedule') }}
        </h2>
        @if ($successMessage)
            <div class="text-green-700 text-md font-semibold text-center grow mx-4">{{ $successMessage }}</div>
        @endif
    </div>

    <div class="px-4 mt-1 flex items-center gap-2 flex-wrap text-sm text-gray-600">
        <span>{{ __('Shift day runs') }}</span>
        @if ($canManage)
            <input type="time" wire:model.live="anchorInput" class="text-sm input-type"
                title="{{ __('Start of the 24-hour production day') }}">
        @else
            <span class="font-semibold">{{ $anchorLabel }}</span>
        @endif
        <span>{{ __('to') }} <span class="font-semibold">{{ $anchorLabel }}</span> {{ __('next day') }}</span>
        @if ($anchorChanged)
            <span class="text-amber-700 font-semibold">
                <i class="fa-solid fa-circle-exclamation"></i>
                {{ __('Day start not saved yet — "Save schedule" applies it with the shifts below.') }}
            </span>
        @endif
    </div>

    <!-- 24h timeline anchored at the team's shift-day anchor -->
    <div class="px-4 mt-5">
        <div class="relative h-12 bg-gray-100 rounded border border-gray-300 overflow-hidden">
            @foreach ($ticks as $tick)
                @if (! $loop->last)
                    <div class="absolute top-0 bottom-0 border-l border-gray-300" style="left: {{ $tick['posPct'] }}%"></div>
                @endif
            @endforeach
            @foreach ($timeline as $block)
                <div wire:key="timeline-{{ $block['index'] }}"
                    class="absolute top-1 bottom-1 rounded text-white text-xs overflow-hidden whitespace-nowrap px-1.5 flex flex-col justify-center {{ $block['invalid'] ? 'border-2 border-dashed border-red-600 opacity-70' : '' }}"
                    style="left: {{ $block['leftPct'] }}%; width: {{ $block['widthPct'] }}%; background-color: {{ $block['color'] ?: '#2563eb' }};"
                    title="{{ $block['name'] }}: {{ $block['label'] }}">
                    <span class="font-semibold leading-tight">{{ $block['name'] }}</span>
                    <span class="leading-tight opacity-90">{{ $block['label'] }}</span>
                </div>
            @endforeach
        </div>
        <div class="relative h-4 mt-1 text-xs text-gray-500">
            @foreach ($ticks as $tick)
                <span class="absolute -translate-x-1/2 text-nowrap {{ $loop->first ? 'translate-x-0' : '' }} {{ $loop->last ? '-translate-x-full' : '' }}"
                    style="left: {{ $tick['posPct'] }}%">{{ $tick['label'] }}{{ $loop->last ? ' (+1d)' : '' }}</span>
            @endforeach
        </div>
    </div>

    <!-- Summary + presets -->
    <div class="px-4 mt-3 flex items-center justify-between flex-wrap gap-2">
        <div class="text-sm">
            <span class="font-semibold">{{ $coverage }}%</span> {{ __('of the shift day covered') }}
            @if ($isValid)
                <span class="text-green-700 font-semibold ms-2"><i class="fa-solid fa-circle-check"></i> {{ __('no overlaps') }}</span>
            @elseif ($scheduleError)
                <span class="text-red-700 font-semibold ms-2">{{ $scheduleError }}</span>
            @endif
        </div>
        @if ($canManage)
            <div class="flex items-center gap-2 text-xs">
                <span class="text-gray-500">{{ __('Presets:') }}</span>
                <button wire:click="applyPreset(1)" class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-100 font-semibold">{{ __('1 shift (10h)') }}</button>
                <button wire:click="applyPreset(2)" class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-100 font-semibold">{{ __('2 shifts (12h)') }}</button>
                <button wire:click="applyPreset(3)" class="px-2 py-1 border border-gray-300 rounded hover:bg-gray-100 font-semibold">{{ __('3 shifts (8h)') }}</button>
            </div>
        @endif
    </div>

    <!-- One card per shift -->
    <div class="px-4 py-4 grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach ($draft as $index => $shift)
            <div wire:key="shift-card-{{ $index }}"
                class="border rounded-lg p-4 bg-white {{ isset($draftErrors[$index]) ? 'border-red-400' : 'border-gray-300' }}">
                <div class="flex items-center justify-between mb-3">
                    <input type="text" maxlength="50" placeholder="{{ __('Shift name') }}"
                        wire:model.live.debounce.400ms="draft.{{ $index }}.name"
                        class="text-sm input-type w-full me-2" @disabled(! $canManage)>
                    @if ($canManage)
                        <button wire:click="removeShift({{ $index }})" class="text-red-500 hover:text-red-700"
                            title="{{ __('Remove shift') }}">
                            <i class="fa-regular fa-trash-can"></i>
                        </button>
                    @endif
                </div>
                <div class="flex items-center mb-2">
                    <label class="w-1/3 text-sm text-nowrap">{{ __('Start Time') }}</label>
                    <div class="w-2/3 flex items-center gap-2">
                        <input type="time" wire:model.live="draft.{{ $index }}.start_time" class="text-sm input-type" @disabled(! $canManage)>
                        @if ($this->startsNextDayBadge($shift['start_time'] ?? null))
                            <span class="text-xs font-semibold text-white bg-gray-500 rounded px-1.5 py-0.5 text-nowrap">{{ __('next day') }}</span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center">
                    <label class="w-1/3 text-sm text-nowrap">{{ __('End Time') }}</label>
                    <div class="w-2/3 flex items-center gap-2">
                        <input type="time" wire:model.live="draft.{{ $index }}.end_time" class="text-sm input-type" @disabled(! $canManage)>
                        @if ($this->endsNextDayBadge($shift['end_time'] ?? null))
                            <span class="text-xs font-semibold text-white bg-gray-500 rounded px-1.5 py-0.5 text-nowrap">{{ __('next day') }}</span>
                        @endif
                    </div>
                </div>
                @if (isset($draftErrors[$index]))
                    <div class="mt-2 text-red-700 text-xs">{{ $draftErrors[$index] }}</div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($canManage)
        <div class="px-4 pb-6 flex items-center gap-3">
            @if (count($draft) < 3)
                <button wire:click="addShift" class="add-btn">
                    <i class="fa-solid fa-plus text-sm"></i> {{ __('Add Shift') }}
                </button>
            @endif
            <button wire:click="save" wire:loading.attr="disabled" @disabled(! $isValid)
                class="save-btn">
                <i class="fa-solid fa-floppy-disk"></i> {{ __('Save schedule') }}
                <span wire:loading wire:target="save"><i class="fa-solid fa-spinner fa-spin"></i></span>
            </button>
            <span class="text-xs text-gray-500">{{ __('Removed shifts are deactivated, not deleted — historical records keep their shift.') }}</span>
        </div>
    @endif
</div>
