@props([
    'options' => [],
    'selected' => [],
    'wireToggle' => null,
    'wireModel' => null,
])

{{--
  Multi-select pill grid.
  Prefer wireToggle method name that receives option value, e.g. toggleDraftShift.
  Or pass wireModel for array property and use wire:click with toggle helper.
--}}
<div class="flex flex-wrap gap-1.5">
    @foreach ($options as $option)
        @php
            $value = is_array($option) ? ($option['value'] ?? $option['id'] ?? '') : $option;
            $label = is_array($option) ? ($option['label'] ?? $option['name'] ?? $value) : $option;
            $isOn = in_array($value, $selected, false) || in_array((string) $value, array_map('strval', (array) $selected), true);
        @endphp
        <button
            type="button"
            @if ($wireToggle)
                wire:click="{{ $wireToggle }}({{ is_numeric($value) ? $value : "'".$value."'" }})"
            @endif
            @class([
                'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-[13px] cursor-pointer',
                'border-[#861e1a] bg-[#861e1a] text-white' => $isOn,
                'border-[#DCD4C0] bg-white text-gray-800 hover:border-[#861e1a]' => ! $isOn,
            ])
        >
            @if ($isOn)
                <svg class="h-[11px] w-[11px] stroke-white" viewBox="0 0 24 24" fill="none" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 6L9 17l-5-5"/>
                </svg>
            @endif
            {{ $label }}
        </button>
    @endforeach
</div>
