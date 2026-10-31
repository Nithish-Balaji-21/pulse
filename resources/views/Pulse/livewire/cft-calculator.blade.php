<div class="relative max-sm:static" x-data="{ open: false }"
    @click.outside="if (open) { open = false; $nextTick(() => document.activeElement === document.body && $refs.trigger.focus({ preventScroll: true })) }"
    @keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }">
    <button type="button" x-ref="trigger" @click="open = ! open" :aria-expanded="open.toString()"
        aria-controls="cft-popover-{{ $this->instanceKey }}" title="Husk calculator"
        :class="open ? 'bg-[#83211b] border-[#83211b] text-white' : 'bg-white border-[#e3dcd6] text-[#3a3430] hover:bg-[#f8f4f1]'"
        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border text-[13px] font-semibold max-lg:w-9 lg:ps-3 lg:pe-3.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#83211b]/30 focus-visible:ring-offset-1">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="size-4 shrink-0">
            <path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z" />
            <path d="M4 7.5l8 4.5 8-4.5" />
            <path d="M12 12v9" />
        </svg>
        <span class="max-lg:sr-only">{{ __('Husk') }}</span>
    </button>

    <section id="cft-popover-{{ $this->instanceKey }}" x-cloak x-show="open" x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
        class="absolute right-0 top-[calc(100%+8px)] z-50 w-[min(350px,calc(100vw-1.5rem))] max-sm:left-3 max-sm:right-3 max-sm:top-[58px] max-sm:w-auto rounded-2xl border border-[#e6e0da] bg-white p-3 shadow-[0_12px_32px_rgba(40,20,10,0.16)]"
        aria-label="Husk calculator">
        <div class="mb-2 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-[#1f1b18]">{{ __('Husk Calculator') }}</h2>
            </div>
            <button type="button" wire:click="resetCalculator" class="rounded-lg px-2 py-1 text-xs font-semibold text-[#7a221e] hover:bg-[#f8ede6]">
                {{ __('Clear') }}
            </button>
        </div>

        <fieldset>
            <div class="grid grid-cols-3 gap-2">
                @foreach ([['key' => 'length', 'label' => __('Length')], ['key' => 'width', 'label' => __('Width')], ['key' => 'height', 'label' => __('Height')]] as $dimension)
                    <div wire:key="cft-{{ $dimension['key'] }}" class="min-w-0 space-y-1">
                        <label for="cft-{{ $this->instanceKey }}-{{ $dimension['key'] }}" class="block truncate text-[11px] font-medium text-[#4b443f]">{{ $dimension['label'] }}</label>
                        <input id="cft-{{ $this->instanceKey }}-{{ $dimension['key'] }}" type="text" inputmode="decimal" maxlength="8" pattern="[0-9]+(\.[0-9]{1,2})?"
                            x-on:input="$el.value = $el.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1').replace(/^(\d+(?:\.\d{0,2})?).*$/, '$1')"
                            wire:model.live.debounce.250ms="dimensions.{{ $dimension['key'] }}"
                            placeholder="{{ ['length' => '25.5', 'width' => '7.6', 'height' => '8.11'][$dimension['key']] }}"
                            class="h-8 w-full rounded-lg border border-[#ded8d2] px-2 text-sm text-[#1f1b18] focus:border-[#83211b] focus:ring-2 focus:ring-[#83211b]/15">
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex items-center justify-between rounded-lg bg-[#f8f1ec] px-2.5 py-1.5" aria-live="polite" aria-atomic="true">
                <span class="text-xs font-medium text-[#6b635c]">{{ __('Volume') }}</span>
                @if ($this->cft === null)
                    <span class="text-xs text-[#8a817a]">— CFT</span>
                @else
                    <span class="text-sm font-bold tabular-nums text-[#83211b]">{{ number_format($this->cft, 2) }} CFT</span>
                @endif
            </div>
        </fieldset>
    </section>
</div>
