@php
    $num = fn ($value) => \App\Services\NumberFormatterService::formatToIndianFormat(round((float) $value), false, 0);
    $stockById = $locations->mapWithKeys(fn ($location) => [$location['id'] => $location['stock']]);
    $nameById = $locations->mapWithKeys(fn ($location) => [$location['id'] => $location['name']]);
    $from = $locations->firstWhere('id', (int) $fromLocationId);
    $label = fn ($location) => ($location['group'] ? $location['group'].' · ' : '').$location['name'];
@endphp

<div>
    @if ($open && $from)
        <div class="pi-modal-backdrop" wire:key="transfer-modal">
            <form wire:submit="save" class="pi-modal pi-modal--transfer"
                x-data="{
                    qty: @js($qty),
                    from: $wire.entangle('fromLocationId').live,
                    to: $wire.entangle('toLocationId').live,
                    stocks: @js($stockById),
                    names: @js($nameById),
                    fmt(v) { return Math.round(v).toLocaleString('en-IN'); },
                    get q() { return parseInt(this.qty, 10) || 0; },
                    get available() { return this.stocks[this.from] ?? 0; },
                    get limit() { return Math.max(0, Math.floor(this.available)); },
                    get destStock() { return this.stocks[this.to] ?? 0; },
                    get over() { return this.q > this.limit; },
                    get ready() { return this.q > 0 && this.to && this.to != this.from; },
                    clean(v) { this.qty = String(v).replace(/\D/g, '').slice(0, 6); $wire.qty = this.qty; },
                    fillMax() { this.clean(this.limit); },
                }">
                <div class="pi-transfer-head">
                    <div class="pi-modal-head">
                        <span class="pi-modal-title">{{ $isEditing ? __('Edit Transfer') : __('Transfer Pith') }}</span>
                        <span class="pi-modal-sub">{{ $isEditing ? __('Change the destination, quantity, date or comment') : __('Move stock between storage locations') }}</span>
                    </div>
                    <button type="button" wire:click="cancel" class="pi-icon-btn" aria-label="{{ __('Close') }}">✕</button>
                </div>

                <div class="pi-transfer-route">
                    <label for="transfer-from" class="pi-transfer-label">{{ __('From') }}</label>
                    <span></span>
                    <label for="transfer-to" class="pi-transfer-label">{{ __('To') }}</label>

                    @if ($fromLocked)
                        <span id="transfer-from" class="pi-select is-static">{{ $label($from) }}</span>
                    @else
                        <select id="transfer-from" wire:model.live="fromLocationId" class="pi-select">
                            @foreach ($fromGroups as $group)
                                @if ($group['is_ungrouped'] && $fromGroups->count() === 1)
                                    @foreach ($group['locations'] as $option)
                                        <option value="{{ $option['id'] }}" wire:key="from-{{ $option['id'] }}">{{ $option['name'] }}</option>
                                    @endforeach
                                @else
                                    <optgroup label="{{ $group['name'] }}" wire:key="from-g-{{ $group['id'] }}">
                                        @foreach ($group['locations'] as $option)
                                            <option value="{{ $option['id'] }}" wire:key="from-{{ $option['id'] }}">{{ $option['name'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            @endforeach
                        </select>
                    @endif

                    @unless ($fromLocked)
                        <button type="button" wire:click="swap" class="pi-transfer-swap" title="{{ __('Swap From and To') }}" aria-label="{{ __('Swap From and To') }}"
                            x-bind:disabled="! to" x-bind:class="{ 'is-disabled': ! to }">⇄</button>
                    @else
                        <span class="pi-transfer-arrow" aria-hidden="true">→</span>
                    @endunless

                    <select id="transfer-to" wire:model.live="toLocationId" class="pi-select @error('toLocationId') is-invalid @enderror">
                        <option value="">{{ __('Select destination') }}</option>
                        @foreach ($toGroups as $group)
                            @if ($group['is_ungrouped'] && $toGroups->count() === 1)
                                @foreach ($group['locations'] as $option)
                                    <option value="{{ $option['id'] }}" wire:key="to-{{ $option['id'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            @else
                                <optgroup label="{{ $group['name'] }}" wire:key="to-g-{{ $group['id'] }}">
                                    @foreach ($group['locations'] as $option)
                                        <option value="{{ $option['id'] }}" wire:key="to-{{ $option['id'] }}">{{ $option['name'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>

                    <div class="pi-transfer-hint">
                        <span>{{ __('In stock') }} <strong x-text="fmt(available) + ' cft'">{{ $num($from['stock']) }} cft</strong></span>
                        @error('fromLocationId') <span class="pi-error">{{ $message }}</span> @enderror
                    </div>
                    <span></span>
                    <div class="pi-transfer-hint">
                        <span x-show="to">{{ __('In stock') }} <strong x-text="fmt(destStock) + ' cft'"></strong></span>
                        <span x-show="! to">{{ __('Choose where the stock goes') }}</span>
                        @error('toLocationId') <span class="pi-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="pi-transfer-inputs">
                    <label class="pi-field">
                        {{ __('Date') }}
                        <input type="date" wire:model="date" max="{{ now()->toDateString() }}" class="pi-date">
                        @error('date') <span class="pi-error">{{ $message }}</span> @enderror
                    </label>

                    <div class="pi-field">
                        <label for="transfer-qty">{{ __('Quantity') }}</label>
                        <div class="pi-qty">
                            <input id="transfer-qty" type="text" inputmode="numeric" autocomplete="off" placeholder="0"
                                x-init="$el.focus()" x-bind:value="qty" x-on:input="clean($event.target.value); $event.target.value = qty">
                            <span class="pi-qty-unit">cft</span>
                            <button type="button" class="pi-qty-max" x-on:click="fillMax()" x-bind:disabled="limit < 1">{{ __('Max') }}</button>
                        </div>
                        <span class="pi-transfer-sub is-red" x-show="over" x-cloak
                            x-text="'{{ __('Only') }} ' + fmt(limit) + ' {{ __('cft in stock') }} · {{ __('exceeds by') }} ' + fmt(q - limit) + ' cft'"></span>
                        <span class="pi-transfer-sub" x-show="! over" x-text="fmt(limit) + ' {{ __('cft in stock') }}'"></span>
                        @error('qty') <span class="pi-error">{{ $message }}</span> @enderror
                    </div>
                </div>

                <label class="pi-field">
                    <span>{{ __('Reason') }} <span class="pi-field-optional">({{ __('optional') }})</span></span>
                    <input type="text" wire:model="note" maxlength="255" autocomplete="off" class="pi-input"
                        placeholder="{{ __('e.g. Moving to drying yard before block pressing') }}">
                    @error('note') <span class="pi-error">{{ $message }}</span> @enderror
                </label>

                <div class="pi-note pi-transfer-impact">
                    <span class="pi-note-label">{{ __('After transfer') }}</span>
                    <div class="pi-transfer-impact-row">
                        <span class="pi-transfer-impact-name" x-text="names[from]"></span>
                        <span class="pi-transfer-delta is-red" x-text="ready ? '−' + fmt(q) : ''"></span>
                        <span class="pi-transfer-change">
                            <span x-text="fmt(available)"></span> → <strong x-bind:class="{ 'is-red': over }" x-text="fmt(ready ? available - q : available)"></strong>
                        </span>
                    </div>
                    <div class="pi-transfer-impact-row">
                        <span class="pi-transfer-impact-name" x-text="to ? names[to] : '{{ __('Destination') }}'"></span>
                        <span class="pi-transfer-delta is-green" x-text="ready ? '+' + fmt(q) : ''"></span>
                        <span class="pi-transfer-change" x-show="to">
                            <span x-text="fmt(destStock)"></span> → <strong x-text="fmt(ready ? destStock + q : destStock)"></strong>
                        </span>
                        <span class="pi-transfer-change" x-show="! to">—</span>
                    </div>
                </div>

                <div class="pi-modal-actions">
                    <button type="button" wire:click="cancel" class="pi-btn pi-btn--lg pi-btn--muted">{{ __('Cancel') }}</button>
                    <button type="submit" class="pi-btn pi-btn--lg pi-btn--solid pi-btn--maroon"
                        x-bind:class="{ 'is-disabled': ! ready }" x-bind:disabled="! ready"
                        wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">
                            @if ($isEditing)
                                {{ __('Save changes') }}
                            @else
                                <span x-text="ready ? '{{ __('Transfer') }} ' + fmt(q) + ' cft' : '{{ __('Transfer') }}'">{{ __('Transfer') }}</span>
                            @endif
                        </span>
                        <span wire:loading wire:target="save">{{ $isEditing ? __('Saving…') : __('Transferring…') }}</span>
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
