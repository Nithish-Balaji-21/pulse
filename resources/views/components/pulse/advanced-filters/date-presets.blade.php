@props([
    'presets' => [
        'today' => 'Today',
        'week' => 'This week',
        'month' => 'This month',
        '30d' => 'Last 30 days',
        'custom' => 'Custom',
    ],
    'selected' => '',
    'from' => '',
    'to' => '',
    'fromModel' => 'draftDateFrom',
    'toModel' => 'draftDateTo',
    'setPresetMethod' => 'setDraftDatePreset',
])

<div>
    <div class="mb-2 flex flex-wrap gap-1.5">
        @foreach ($presets as $key => $label)
            <button
                type="button"
                wire:click="{{ $setPresetMethod }}('{{ $key }}')"
                @class([
                    'rounded-full border px-3 py-1.5 text-xs cursor-pointer',
                    'border-[#861e1a] bg-[#861e1a] font-medium text-white' => $selected === $key,
                    'border-[#DCD4C0] bg-white text-gray-800 hover:border-[#861e1a]' => $selected !== $key,
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($selected === 'custom')
        <div class="mt-2 flex items-center gap-1.5">
            <input
                type="date"
                wire:model.live.debounce.400ms="{{ $fromModel }}"
                class="rounded-lg border border-[#DCD4C0] bg-white px-2 py-1.5 text-[13px] text-gray-800"
            />
            <span class="text-xs text-gray-500">to</span>
            <input
                type="date"
                wire:model.live.debounce.400ms="{{ $toModel }}"
                class="rounded-lg border border-[#DCD4C0] bg-white px-2 py-1.5 text-[13px] text-gray-800"
            />
        </div>
    @endif
</div>
