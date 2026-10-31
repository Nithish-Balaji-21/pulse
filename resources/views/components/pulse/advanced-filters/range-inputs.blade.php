@props([
    'minModel',
    'maxModel',
    'minPlaceholder' => 'Min',
    'maxPlaceholder' => 'Max',
    'step' => null,
    'hint' => null,
])

<div>
    <div class="flex items-center gap-2">
        <input
            type="number"
            @if ($step) step="{{ $step }}" @endif
            wire:model.live.debounce.400ms="{{ $minModel }}"
            placeholder="{{ $minPlaceholder }}"
            class="w-[100px] rounded-lg border border-[#DCD4C0] bg-white px-2.5 py-1.5 text-[13px] text-gray-800"
        />
        <span class="text-xs text-gray-500">—</span>
        <input
            type="number"
            @if ($step) step="{{ $step }}" @endif
            wire:model.live.debounce.400ms="{{ $maxModel }}"
            placeholder="{{ $maxPlaceholder }}"
            class="w-[100px] rounded-lg border border-[#DCD4C0] bg-white px-2.5 py-1.5 text-[13px] text-gray-800"
        />
    </div>
    @if ($hint)
        <p class="mt-1.5 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
