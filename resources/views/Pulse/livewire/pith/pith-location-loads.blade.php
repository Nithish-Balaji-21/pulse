@php
    $num = function ($value) {
        $value = round((float) $value);

        return \App\Services\NumberFormatterService::formatToIndianFormat($value == 0 ? 0 : $value, false, 0);
    };
    $day = fn (?string $date) => $date ? \Illuminate\Support\Carbon::parse($date)->format('d M Y') : '';
    $range = function (array $batch, bool $todayAsDate = false) use ($day) {
        if (! $batch['start_date']) {
            return __('No entries yet');
        }
        $start = \Illuminate\Support\Carbon::parse($batch['start_date']);
        $end = $batch['end_date'] ? \Illuminate\Support\Carbon::parse($batch['end_date']) : null;
        if (! $end) {
            return $start->format('d M Y').' – '.($todayAsDate ? now()->format('d M Y') : __('Today'));
        }

        return ($start->isSameYear($end) ? $start->format('d M') : $start->format('d M Y')).' – '.$end->format('d M Y');
    };
    $cycles = fn (int $count) => $count === 1 ? __('1 cycle') : __(':count cycles', ['count' => $count]);
    $pct = fn ($out, $in) => $in > 0 ? min(100, max(0, round($out / $in * 100))) : 0;

    $isPast = ! $batch['is_current'];
    $batchName = __('Batch :number', ['number' => $batch['number']]);
    $batchLine = $isPast
        ? __('Closed batch · :range', ['range' => $range($batch)])
        : ($isEmptyBatch ? __(':batch · empty', ['batch' => $batchName]) : __(':batch · since :date', ['batch' => $batchName, 'date' => $day($batch['start_date'])]));

    $kindMeta = [
        'in' => ['label' => __('Inward'), 'unit' => ' cft', 'dot' => 'pi-dot--in'],
        'out' => ['label' => __('Outward'), 'unit' => ' cft', 'dot' => 'pi-dot--out'],
        'water' => ['label' => __('Watering'), 'unit' => ' L', 'dot' => 'pi-dot--water'],
    ];
    $entryCount = $entries->count();
@endphp

<div class="pi-panel-inner">
    <div class="pi-panel-head">
        <div class="pi-panel-title">
            <span class="pi-panel-name">
                {{ $locationName }}
                @unless ($locationIsActive)
                    <span class="pi-tag pi-tag--inactive">{{ __('Inactive') }}</span>
                @endunless
            </span>
            <span class="pi-panel-sub">{{ $batchLine }}</span>
        </div>
        <div class="pi-panel-tools">
            @if (count($pastBatches) > 0)
                <button type="button" wire:click="openPastBatches" class="pi-btn pi-btn--sm">
                    <i class="fa-solid fa-box-archive"></i>{{ __('Past Batches') }}<span class="pi-btn-note">({{ count($pastBatches) }})</span>
                </button>
            @endif
            @if ($canCloseBatch)
                <button type="button" wire:click="confirmCloseLocation" class="pi-btn pi-btn--soft-danger"><i class="fa-solid fa-lock"></i>{{ __('Close Batch') }}</button>
            @endif
            <button type="button" wire:click="closePanel" class="pi-icon-btn" aria-label="{{ __('Close panel') }}">✕</button>
        </div>
    </div>

    <div class="pi-panel-body">
        @if ($isPast)
            <div class="pi-banner">
                <div class="pi-banner-text">
                    <span class="pi-banner-label">{{ __('VIEWING CLOSED BATCH · READ-ONLY') }}</span>
                    <span class="pi-banner-value">{{ $batchName }} · {{ $range($batch) }}</span>
                </div>
                <button type="button" wire:click="backToCurrent" class="pi-btn pi-btn--sm pi-btn--solid pi-btn--maroon"><i class="fa-solid fa-arrow-left"></i>{{ __('Back to current batch') }}</button>
            </div>
        @endif

        <div class="pi-box pi-box--pad">
            <div class="pi-card-head">
                <span class="pi-box-title">{{ $isPast ? __('Stock in this batch') : __('Current batch') }}</span>
                <span class="pi-card-meta">{{ __('in cft') }}</span>
            </div>
            <div class="pi-bar {{ $batch['inward'] > 0 ? '' : 'is-empty' }}"><span style="width: {{ $pct($batch['outward'], $batch['inward']) }}%"></span></div>
            <div class="pi-stats">
                <div class="pi-stat"><span class="pi-stat-label is-in">{{ __('Inward') }}</span><span class="pi-stat-value">{{ $num($batch['inward']) }}</span></div>
                <div class="pi-stat"><span class="pi-stat-label is-out">{{ __('Outward') }}</span><span class="pi-stat-value">{{ $num($batch['outward']) }}</span></div>
                <div class="pi-stat"><span class="pi-stat-label is-stock">{{ $isPast ? __('Remaining') : __('In Stock') }}</span><span class="pi-stat-value">{{ $num($batch['stock']) }}</span></div>
                <button type="button" wire:click="filterEntries('water')" class="pi-stat-link">
                    <div>
                        <span class="pi-stat-label is-water">{{ __('Watered') }}</span>
                        <span class="pi-stat-value is-water-ink">{{ $num($batch['water_litres']) }} L</span>
                        @if ($batch['water_cycles'] > 0)
                            <span class="pi-stat-sub">{{ $cycles($batch['water_cycles']) }}</span>
                        @endif
                    </div>
                    <span class="pi-chevron">›</span>
                </button>
            </div>
        </div>

        <div class="pi-box pi-box--clip">
            <div class="pi-entries-head">
                <div class="pi-entries-title">
                    <span class="pi-box-title">{{ __('Entries') }}</span>
                    <span>{{ $entryCount === 1 ? __('1 entry') : __(':count entries', ['count' => $entryCount]) }}</span>
                </div>
                <div class="pi-seg">
                    <button type="button" wire:click="filterEntries('all')" class="pi-seg-item {{ $entryFilter === 'all' ? 'is-active' : '' }}">{{ __('All') }}</button>
                    <button type="button" wire:click="filterEntries('stock')" class="pi-seg-item {{ $entryFilter === 'stock' ? 'is-active' : '' }}">{{ __('Stock') }}</button>
                    <button type="button" wire:click="filterEntries('water')" class="pi-seg-item is-water {{ $entryFilter === 'water' ? 'is-active' : '' }}">{{ __('Watering') }}</button>
                </div>
                @unless ($isPast)
                    <div class="pi-actions">
                        @if ($can['in']['create'])
                            <button type="button" wire:click="openForm('in')" class="pi-btn pi-btn--sm pi-btn--solid pi-btn--maroon"><i class="fa-solid fa-plus"></i>{{ __('Stock In') }}</button>
                        @endif
                        @if ($can['out']['create'])
                            <button type="button" wire:click="openForm('out')" class="pi-btn pi-btn--sm pi-btn--solid pi-btn--orange"><i class="fa-solid fa-minus"></i>{{ __('Stock Out') }}</button>
                        @endif
                        @if ($can['water']['create'])
                            <button type="button" wire:click="openForm('water')" class="pi-btn pi-btn--sm pi-btn--solid pi-btn--blue"><i class="fa-solid fa-droplet"></i>{{ __('Watering') }}</button>
                        @endif
                        @if ($canTransfer)
                            <button type="button" x-on:click="$dispatch('open-pith-transfer', { fromLocationId: {{ $locationId }} })" class="pi-btn pi-btn--sm pi-btn--transfer">⇄ {{ __('Transfer') }}</button>
                        @endif
                    </div>
                @endunless
            </div>

            <div class="pi-grid pi-cols-entries pi-entries-thead">
                <div>{{ __('Date') }}</div>
                <div>{{ __('Entry') }}</div>
                <div class="pi-num">{{ __('Quantity') }}</div>
                <div style="padding-left: 20px">{{ __('Source') }}</div>
                <div class="pi-num">{{ __('Actions') }}</div>
            </div>

            @forelse ($entries as $entry)
                @php
                    $meta = $kindMeta[$entry['kind']];
                    $transfer = $entry['transfer'] ?? null;
                @endphp
                <div class="pi-grid pi-cols-entries pi-entry" wire:key="entry-{{ $entry['key'] }}">
                    <div class="pi-entry-date">{{ $day($entry['date']) }}</div>
                    <div class="pi-entry-kind">
                        <span class="pi-dot {{ $meta['dot'] }}"></span>
                        @if ($transfer)
                            <span class="pi-entry-kind-text">
                                <span>{{ $transfer['direction'] === 'out' ? __('Transfer Out → :location', ['location' => $transfer['peer_location_name']]) : __('Transfer In ← :location', ['location' => $transfer['peer_location_name']]) }}</span>
                                @if ($transfer['note'])
                                    <span class="pi-entry-sub">{{ $transfer['note'] }}</span>
                                @endif
                            </span>
                        @else
                            <span>{{ $meta['label'] }}</span>
                        @endif
                    </div>
                    <div class="pi-entry-qty {{ $entry['kind'] === 'water' ? 'is-water-ink' : '' }}">{{ $transfer ? ($transfer['direction'] === 'out' ? '−' : '+') : '' }}{{ $num($entry['qty']) }}{{ $meta['unit'] }}</div>
                    <div class="pi-entry-source">
                        @if ($transfer)
                            <span class="pi-source-tag {{ $transfer['direction'] === 'out' ? 'is-out' : 'is-in' }}">{{ __('Transfer') }}</span>
                        @else
                            {{ $entry['source'] === 'production' ? __('Production') : __('Manual') }}
                        @endif
                    </div>
                    <div class="pi-entry-actions">
                        @if ($transfer && $transfer['changeable'] && $canEditTransfer)
                            <button type="button" x-on:click="$dispatch('edit-pith-transfer', { transferId: {{ $transfer['id'] }}, locationId: {{ $locationId }} })" class="pi-act pi-act--icon" title="{{ __('Edit transfer') }}" aria-label="{{ __('Edit transfer') }}"><i class="fa-regular fa-pen-to-square"></i></button>
                        @endif
                        @if ($transfer && $transfer['changeable'] && $canDeleteTransfer)
                            <button type="button" wire:click="confirmDeleteTransfer({{ $transfer['id'] }})" class="pi-act pi-act--icon pi-act--del" title="{{ __('Delete transfer') }}" aria-label="{{ __('Delete transfer') }}"><i class="fa-regular fa-trash-can"></i></button>
                        @endif
                        @if ($entry['editable'])
                            @if ($can[$entry['kind']]['edit'])
                                <button type="button" wire:click="editEntry('{{ $entry['kind'] }}', {{ $entry['id'] }})" class="pi-act pi-act--icon" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><i class="fa-regular fa-pen-to-square"></i></button>
                            @endif
                            @if ($can[$entry['kind']]['delete'])
                                <button type="button" wire:click="confirmDeleteEntry('{{ $entry['kind'] }}', {{ $entry['id'] }})" class="pi-act pi-act--icon pi-act--del" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><i class="fa-regular fa-trash-can"></i></button>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <div class="pi-entries-empty">
                    @if ($isEmptyBatch)
                        <span class="pi-empty-title">{{ __('New batch, nothing yet') }}</span>
                        <span class="pi-empty-text">{{ __('Use + Stock In to start filling this location.') }}</span>
                    @else
                        <span class="pi-empty-title">{{ __('No entries for this filter') }}</span>
                        <span class="pi-empty-text">{{ __('Switch to All to see every entry.') }}</span>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- Stock In / Stock Out / Watering form --}}
    @if ($formType)
        @php
            $formMeta = [
                'in' => ['title' => __('Stock In'), 'label' => __('Quantity received'), 'unit' => 'cft', 'qtyClass' => '', 'btn' => 'pi-btn--maroon'],
                'out' => ['title' => __('Stock Out'), 'label' => __('Quantity sent out'), 'unit' => 'cft', 'qtyClass' => 'pi-qty--out', 'btn' => 'pi-btn--orange'],
                'water' => ['title' => __('Watering'), 'label' => __('Water used'), 'unit' => __('litres'), 'qtyClass' => 'pi-qty--water', 'btn' => 'pi-btn--blue'],
            ][$formType];
            $oldQty = $formEntry ? (float) $formEntry['qty'] : 0;
            $stockBase = (float) $batch['stock'] + ($formEntry && $formType === 'out' ? $oldQty : 0) - ($formEntry && $formType === 'in' ? $oldQty : 0);
            $waterBase = (int) $batch['water_litres'] - ($formEntry && $formType === 'water' ? (int) $oldQty : 0);
        @endphp
        <div class="pi-modal-backdrop" wire:key="entry-form">
            <form wire:submit="saveForm" class="pi-modal pi-modal--form"
                x-data="{
                    qty: @js($formQty),
                    kind: @js($formType),
                    stock: {{ round($stockBase) }},
                    water: {{ $waterBase }},
                    fmt(v) { return Math.round(v).toLocaleString('en-IN'); },
                    get q() { return parseInt(this.qty, 10) || 0; },
                    get over() { return this.kind === 'out' && this.q > this.stock; },
                    clean(v) { this.qty = String(v).replace(/\D/g, '').slice(0, 6); $wire.formQty = this.qty; },
                }">
                <div class="pi-modal-head">
                    <span class="pi-modal-title">{{ ($formEntryId ? __('Edit') : __('Add')).' '.$formMeta['title'] }}</span>
                    <span class="pi-modal-sub">{{ $locationName }} · {{ $batchName }}</span>
                </div>

                <label class="pi-field">
                    {{ $formMeta['label'] }}
                    <div class="pi-qty {{ $formMeta['qtyClass'] }}">
                        <input type="text" inputmode="numeric" autocomplete="off" placeholder="0"
                            x-init="$el.focus()" x-bind:value="qty" x-on:input="clean($event.target.value); $event.target.value = qty">
                        <span class="pi-qty-unit">{{ $formMeta['unit'] }}</span>
                    </div>
                    @error('formQty') <span class="pi-error">{{ $message }}</span> @enderror
                </label>

                <label class="pi-field">
                    {{ __('Date') }}
                    <input type="date" wire:model="formDate" max="{{ now()->toDateString() }}" class="pi-date">
                    @error('formDate') <span class="pi-error">{{ $message }}</span> @enderror
                </label>

                <div class="pi-note">
                    <template x-if="kind === 'water'">
                        <div style="display: contents">
                            <span class="pi-note-label">{{ __('Total watered this batch') }}</span>
                            <span class="pi-note-value" x-text="fmt(water) + ' → ' + fmt(water + q) + ' L'"></span>
                        </div>
                    </template>
                    <template x-if="kind !== 'water' && over">
                        <div style="display: contents">
                            <span class="pi-note-label" x-text="'{{ __('Only') }} ' + fmt(stock) + ' {{ __('cft in stock') }}'"></span>
                            <span class="pi-note-value is-red" x-text="'{{ __('Exceeds stock by') }} ' + fmt(q - stock) + ' cft'"></span>
                        </div>
                    </template>
                    <template x-if="kind !== 'water' && ! over">
                        <div style="display: contents">
                            <span class="pi-note-label">{{ __('In stock after saving') }}</span>
                            <span class="pi-note-value" x-text="fmt(stock) + ' → ' + fmt(kind === 'out' ? stock - q : stock + q) + ' cft'"></span>
                        </div>
                    </template>
                </div>

                <div class="pi-modal-actions">
                    <button type="button" wire:click="cancelForm" class="pi-btn pi-btn--lg pi-btn--muted">{{ __('Cancel') }}</button>
                    <button type="submit" class="pi-btn pi-btn--lg pi-btn--solid {{ $formMeta['btn'] }}"
                        x-bind:class="{ 'is-disabled': q < 1 }" x-bind:disabled="q < 1"
                        wire:loading.attr="disabled" wire:target="saveForm">
                        {{ $formEntryId ? __('Save changes') : __('Save') }}
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Delete confirmation --}}
    @if ($deletingEntry)
        @php $meta = $kindMeta[$deletingEntry['kind']]; @endphp
        <div class="pi-modal-backdrop" wire:key="entry-delete">
            <div class="pi-modal pi-modal--sm">
                <div class="pi-modal-title pi-modal-title--sm">{{ __('Delete this :kind entry?', ['kind' => strtolower($meta['label'])]) }}</div>
                <div class="pi-note">
                    <span class="pi-note-value pi-note-value--sm">{{ $num($deletingEntry['qty']) }}{{ $meta['unit'] }}</span>
                    <span class="pi-note-text">{{ $day($deletingEntry['date']) }} · {{ __('Totals will update') }}</span>
                </div>
                <div class="pi-modal-actions">
                    <button type="button" wire:click="cancelDeleteEntry" class="pi-btn pi-btn--lg pi-btn--muted">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="deleteConfirmedEntry" wire:loading.attr="disabled" class="pi-btn pi-btn--lg pi-btn--solid pi-btn--red">{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete transfer confirmation --}}
    @if ($deletingTransfer)
        <div class="pi-modal-backdrop" wire:key="transfer-delete">
            <div class="pi-modal pi-modal--sm">
                <div class="pi-modal-title pi-modal-title--sm">{{ __('Delete this transfer?') }}</div>
                <div class="pi-note">
                    <span class="pi-note-value pi-note-value--sm">{{ $num($deletingTransfer['qty']) }} cft</span>
                    <span class="pi-note-text">
                        {{ $deletingTransfer['direction'] === 'out'
                            ? __(':from → :to', ['from' => $locationName, 'to' => $deletingTransfer['peer']])
                            : __(':from → :to', ['from' => $deletingTransfer['peer'], 'to' => $locationName]) }}
                        · {{ $day($deletingTransfer['date']) }}
                    </span>
                    <span class="pi-note-text">{{ __('Totals at both locations will update.') }}</span>
                </div>
                <div class="pi-modal-actions">
                    <button type="button" wire:click="cancelDeleteTransfer" class="pi-btn pi-btn--lg pi-btn--muted">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="deleteConfirmedTransfer" wire:loading.attr="disabled" class="pi-btn pi-btn--lg pi-btn--solid pi-btn--red">{{ __('Delete') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Close batch --}}
    @if ($showCloseDialog && $batch['is_current'])
        @php $notTakenOut = round($batch['stock']); @endphp
        <div class="pi-modal-backdrop" wire:key="close-batch">
            <div class="pi-modal pi-modal--close">
                <div class="pi-modal-head">
                    <span class="pi-modal-title">{{ __('Close :batch?', ['batch' => $batchName]) }}</span>
                    <span class="pi-modal-sub">{{ $locationName }} · {{ $range($batch, true) }}</span>
                </div>
                <div class="pi-close-grid">
                    <div><span class="is-in">{{ __('Inward') }}</span><span>{{ $num($batch['inward']) }} cft</span></div>
                    <div><span class="is-out">{{ __('Outward') }}</span><span>{{ $num($batch['outward']) }} cft</span></div>
                    <div><span class="is-water">{{ __('Watered') }}</span><span>{{ $num($batch['water_litres']) }} L</span></div>
                </div>
                @if ($notTakenOut > 0)
                    <div class="pi-dev pi-dev--short">
                        <span class="pi-dev-label">{{ __('Stock not taken out') }}</span>
                        <span class="pi-dev-value">{{ $num($notTakenOut) }} cft</span>
                        <span class="pi-dev-note">{{ __('Recorded as a shortfall against Inward when you close.') }}</span>
                    </div>
                @else
                    <div class="pi-dev pi-dev--clear">
                        <span class="pi-dev-label">{{ __('All stock taken out') }}</span>
                        <span class="pi-dev-value">{{ $notTakenOut < 0 ? __(':cft cft overdrawn', ['cft' => $num(abs($notTakenOut))]) : __('0 cft left') }}</span>
                        <span class="pi-dev-note">{{ __('No shortfall to record.') }}</span>
                    </div>
                @endif
                <div class="pi-close-text">{{ __('The batch moves to Past Batches (read-only) and :location starts empty as :next.', ['location' => $locationName, 'next' => __('Batch :number', ['number' => $batch['number'] + 1])]) }}</div>
                <div class="pi-modal-actions">
                    <button type="button" wire:click="cancelClose" wire:loading.attr="disabled" wire:target="closeLocationStock" class="pi-btn pi-btn--lg pi-btn--muted">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="closeLocationStock" wire:loading.attr="disabled" wire:target="closeLocationStock" class="pi-btn pi-btn--lg pi-btn--solid pi-btn--maroon">{{ __('Close :batch', ['batch' => $batchName]) }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Past batches --}}
    @if ($showPastBatches)
        <div class="pi-modal-backdrop" wire:key="past-batches" wire:click.self="closePastBatches">
            <div class="pi-modal pi-modal--table">
                <div class="pi-modal-bar">
                    <div>
                        <span class="pi-modal-title">{{ __('Past Batches') }}</span>
                        <span class="pi-modal-sub">{{ $locationName }} · {{ count($pastBatches) === 1 ? __('1 closed batch') : __(':count closed batches', ['count' => count($pastBatches)]) }}</span>
                    </div>
                    <button type="button" wire:click="closePastBatches" class="pi-icon-btn" aria-label="{{ __('Close') }}">✕</button>
                </div>
                <div class="pi-grid pi-cols-batches pi-batches-thead">
                    <div>{{ __('Batch') }}</div>
                    <div>{{ __('Period') }}</div>
                    <div class="pi-num is-in">{{ __('Inward') }}</div>
                    <div class="pi-num is-out">{{ __('Outward') }}</div>
                    <div class="pi-num is-red">{{ __('Not taken out') }}</div>
                    <div class="pi-num is-water">{{ __('Watered') }}</div>
                    <div></div>
                </div>
                <div style="overflow-y: auto; max-height: 60vh">
                    @foreach ($pastBatches as $past)
                        <div class="pi-grid pi-cols-batches pi-batch-row" role="button" tabindex="0"
                            wire:key="past-{{ $past['depletion_id'] }}" wire:click="viewBatch({{ $past['depletion_id'] }})">
                            <div>{{ __('Batch :number', ['number' => $past['number']]) }}</div>
                            <div class="pi-soft">{{ $range($past) }}</div>
                            <div class="pi-num">{{ $num($past['inward']) }}</div>
                            <div class="pi-num">{{ $num($past['outward']) }}</div>
                            <div class="pi-num" style="font-weight: 600; color: {{ round($past['stock']) > 0 ? 'var(--pi-red)' : 'var(--pi-green-dark)' }}">{{ $num($past['stock']) }}</div>
                            <div class="pi-batch-water">
                                <span class="is-water-ink">{{ $num($past['water_litres']) }} L</span>
                                @if ($past['water_cycles'] > 0)
                                    <span style="font-size: 12px" class="is-water">{{ $cycles($past['water_cycles']) }}</span>
                                @endif
                            </div>
                            <div class="pi-num is-in" style="font-weight: 600">{{ __('View') }} ›</div>
                        </div>
                    @endforeach
                </div>
                <div class="pi-batches-foot">{{ __('Quantities in cft · water in litres') }}</div>
            </div>
        </div>
    @endif
</div>
