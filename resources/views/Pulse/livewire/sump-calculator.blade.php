<div class="relative max-sm:static" x-data="{ open: false }"
    @click.outside="if (open) { open = false; $nextTick(() => document.activeElement === document.body && $refs.trigger.focus({ preventScroll: true })) }"
    @keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }">
    <button type="button" x-ref="trigger" @click="open = ! open" :aria-expanded="open.toString()"
        aria-controls="sump-popover-{{ $this->instanceKey }}" title="Sump calculator"
        :class="open ? 'bg-[#83211b] border-[#83211b] text-white' : 'bg-white border-[#e3dcd6] text-[#3a3430] hover:bg-[#f8f4f1]'"
        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border text-[13px] font-semibold max-lg:w-9 lg:ps-3 lg:pe-3.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#83211b]/30 focus-visible:ring-offset-1">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="size-4 shrink-0">
            <path d="M4 4v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4" />
            <path d="M4 11q2.7-1.6 5.3 0t5.4 0 5.3 0" />
        </svg>
        <span class="max-lg:sr-only">{{ __('Sump') }}</span>
    </button>

    <section id="sump-popover-{{ $this->instanceKey }}" x-cloak x-show="open" x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
        class="absolute right-0 top-[calc(100%+8px)] z-50 w-[min(360px,calc(100vw-1.5rem))] max-sm:left-3 max-sm:right-3 max-sm:top-[58px] max-sm:w-auto rounded-2xl border border-[#e6e0da] bg-white p-3 shadow-[0_12px_32px_rgba(40,20,10,0.16)]"
        aria-label="Sump calculator">
        <div class="mb-2 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-[#1f1b18]">{{ __('Sump Calculator') }}</h2>
            </div>
            <button type="button" wire:click="resetCalculator" class="rounded-lg px-2 py-1 text-xs font-semibold text-[#7a221e] hover:bg-[#f8ede6]">
                {{ __('Clear') }}
            </button>
        </div>

        <div class="mb-2 grid grid-cols-2 gap-1.5" role="group" aria-label="Sump shape">
            <button type="button" wire:click="$set('shape', 'rectangular')"
                @class(['h-8 rounded-lg border text-xs font-semibold', 'border-[#83211b] bg-[#f8ede6] text-[#83211b]' => $shape === 'rectangular', 'border-[#ded8d2] text-[#6b635c] hover:bg-[#faf8f6]' => $shape !== 'rectangular'])>
                <i class="fa-regular fa-square me-1.5"></i>{{ __('Rectangular') }}
            </button>
            <button type="button" wire:click="$set('shape', 'circular')"
                @class(['h-8 rounded-lg border text-xs font-semibold', 'border-[#83211b] bg-[#f8ede6] text-[#83211b]' => $shape === 'circular', 'border-[#ded8d2] text-[#6b635c] hover:bg-[#faf8f6]' => $shape !== 'circular'])>
                <i class="fa-regular fa-circle me-1.5"></i>{{ __('Circular') }}
            </button>
        </div>

        <fieldset>
            <legend class="sr-only">{{ __('Sump dimensions') }}</legend>
            <div @class(['grid gap-2', 'grid-cols-3' => $shape === 'rectangular', 'grid-cols-2' => $shape === 'circular'])>
                @foreach ([['key' => 'length', 'label' => $shape === 'circular' ? __('Diameter') : __('Length'), 'placeholder' => '25.5'], ['key' => 'width', 'label' => __('Width'), 'placeholder' => '7.6'], ['key' => 'depth', 'label' => __('Depth'), 'placeholder' => '8.11']] as $dimension)
                    @if ($dimension['key'] !== 'width' || $shape === 'rectangular')
                        <div wire:key="sump-{{ $dimension['key'] }}" class="min-w-0 space-y-1">
                            <label for="sump-{{ $this->instanceKey }}-{{ $dimension['key'] }}" class="block truncate text-[11px] font-medium text-[#4b443f]">{{ $dimension['label'] }}</label>
                            <input id="sump-{{ $this->instanceKey }}-{{ $dimension['key'] }}" type="text" inputmode="decimal" maxlength="8" pattern="[0-9]+(\.[0-9]{1,2})?"
                                x-on:input="$el.value = $el.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1').replace(/^(\d+(?:\.\d{0,2})?).*$/, '$1')"
                                wire:model.live.debounce.250ms="dimensions.{{ $dimension['key'] }}"
                                placeholder="{{ $dimension['placeholder'] }}"
                                class="h-8 w-full rounded-lg border border-[#ded8d2] px-2 text-sm text-[#1f1b18] focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/15">
                        </div>
                    @endif
                @endforeach
            </div>
        </fieldset>

        <div class="mt-2 rounded-xl border border-[#f1dfce] bg-[#fff8ed] p-2.5" aria-live="polite" aria-atomic="true">
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs font-medium text-[#6b635c]">{{ __('Estimated capacity') }}</span>
                @if ($this->capacity !== null)
                    <span class="text-lg font-bold tabular-nums text-[#83211b]">{{ number_format($this->capacity, 2) }} CFT</span>
                @else
                    <span class="text-sm text-[#8a817a]">— CFT</span>
                @endif
            </div>
        </div>
    </section>
</div>
