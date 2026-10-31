@php
    $num = function ($value) {
        $value = round((float) $value);

        return \App\Services\NumberFormatterService::formatToIndianFormat($value == 0 ? 0 : $value, false, 0);
    };
    $signed = fn ($value) => (round($value) > 0 ? '+' : (round($value) < 0 ? '−' : '')).$num(abs($value));
    $day = fn (?string $date) => $date ? \Illuminate\Support\Carbon::parse($date)->format(config('app.date_format')) : '';

    $available = $summary['opening'] + ($summary['received'] ?? 0) + $summary['correction'];
    $consumedPct = $available > 0 ? (int) min(100, max(0, round($summary['consumed'] / $available * 100))) : 0;
    $colsClass = $canUpdate ? 'pi-cols-husk' : 'pi-cols-husk pi-cols-husk--ro';
@endphp

<div class="pi"
    x-data="{ view: 'list', toast: null, toastError: false, timer: null,
        showToast(detail) { this.toast = detail.message; this.toastError = !! detail.error; clearTimeout(this.timer); this.timer = setTimeout(() => this.toast = null, 5000); } }"
    x-on:pi-toast.window="showToast($event.detail)">

    <div class="pi-page">
        <div class="pi-head">
            <div class="pi-head-text">
                <span class="pi-title">{{ __('Husk Inventory') }}</span>
                <span class="pi-subtitle">{{ __('Daily husk stock, received and consumed') }}</span>
            </div>
            <div class="pi-actions">
                @if ($canReport)
                    <a href="{{ route('reports.inventory.husk', ['team' => $teamId]) }}" class="pi-btn"><i class="fa-solid fa-file-lines"></i>{{ __('Inventory Report') }}</a>
                @endif
                @if ($canUpdate)
                    <button type="button" wire:click="openUpdateModal" class="pi-btn pi-btn--solid pi-btn--maroon"><i class="fa-regular fa-pen-to-square"></i>{{ __('Update Stock') }}</button>
                @endif
            </div>
        </div>

        @if ($summary['pending'])
            <div class="pi-banner">
                <div class="pi-banner-text">
                    <span class="pi-banner-label">{{ __('YESTERDAY’S ENTRY PENDING') }}</span>
                    <span class="pi-banner-value">{{ __('Husks received on :date not entered yet', ['date' => $day(now()->subDay()->toDateString())]) }}</span>
                </div>
                @if ($canUpdate)
                    <button type="button" wire:click="openUpdateModal" class="pi-btn pi-btn--sm pi-btn--solid pi-btn--maroon">{{ __('Enter now') }}</button>
                @endif
            </div>
        @endif

        <div class="pi-card pi-card--pad">
            <div class="pi-card-head">
                <span class="pi-card-title">{{ __('Husk Stock') }}</span>
                <span class="pi-card-meta">
                    @if (! $summary['date'])
                        {{ __('No entries yet') }}
                    @elseif ($summary['is_yesterday'])
                        {{ __('Yesterday · :date · cft', ['date' => $day($summary['date'])]) }}
                    @else
                        {{ __('Latest · :date · cft', ['date' => $day($summary['date'])]) }}
                    @endif
                </span>
            </div>
            <div class="pi-bar {{ $available > 0 ? '' : 'is-empty' }}" title="{{ __('Consumed :pct% of opening and received', ['pct' => $consumedPct]) }}"><span style="width: {{ $consumedPct }}%"></span></div>
            <div class="pi-stats">
                <div class="pi-stat"><span class="pi-stat-label">{{ __('Opening') }}</span><span class="pi-stat-value">{{ $num($summary['opening']) }}</span></div>
                <div class="pi-stat">
                    <span class="pi-stat-label is-in">{{ __('Received') }}</span>
                    <span class="pi-stat-value">{{ $summary['received'] !== null ? $num($summary['received']) : '—' }}</span>
                    @if ($summary['date'] && $summary['received'] === null)
                        <span class="pi-stat-sub is-muted">{{ __('Not entered') }}</span>
                    @endif
                </div>
                <div class="pi-stat"><span class="pi-stat-label is-out">{{ __('Consumed') }}</span><span class="pi-stat-value">{{ $num($summary['consumed']) }}</span></div>
                <div class="pi-stat"><span class="pi-stat-label is-stock">{{ __('In Stock') }}</span><span class="pi-stat-value {{ round($summary['stock']) < 0 ? 'is-red' : '' }}">{{ $num($summary['stock']) }}</span></div>
            </div>
            <div class="pi-card-foot">
                @if ($summary['last_received'])
                    {{ __('Last received') }}: <strong>{{ $num($summary['last_received']['qty']) }} cft</strong> {{ __('on :date', ['date' => $day($summary['last_received']['date'])]) }}
                @else
                    {{ __('No husks received yet') }}
                @endif
                @if (round($summary['correction']) != 0)
                    · {{ __('Correction') }}: <strong>{{ $signed($summary['correction']) }} cft</strong>@if ($summary['comment']) ({{ $summary['comment'] }})@endif
                @endif
            </div>
        </div>

        {{-- Not clipped: the month picker drops below the card head and may overhang a short card. --}}
        <div class="pi-card pi-hk-card">
            <div class="pi-entries-head">
                <div class="pi-entries-title">
                    <span class="pi-box-title">{{ __('Daily Stock') }}</span>
                    <span>{{ __('Received, consumed and closing stock per day · cft') }}</span>
                </div>
                <div class="pi-seg">
                    <button type="button" class="pi-seg-item" x-bind:class="{ 'is-active': view === 'list' }" x-on:click="view = 'list'"><i class="fa-solid fa-list-ul"></i> {{ __('List') }}</button>
                    <button type="button" class="pi-seg-item" x-bind:class="{ 'is-active': view === 'chart' }" x-on:click="view = 'chart'"><i class="fa-solid fa-chart-line"></i> {{ __('Chart') }}</button>
                </div>
                <div class="pi-hk-monthpick-wrap"
                    x-data="{
                        open: false,
                        year: 0,
                        current: @js(now()->format('Y-m')),
                        names: @js(collect(range(1, 12))->map(fn ($m) => \Illuminate\Support\Carbon::create(2000, $m, 1)->format('M'))->all()),
                        ym(m) { return this.year + '-' + String(m).padStart(2, '0'); },
                        get firstYear() { return +$wire.firstMonth.slice(0, 4); },
                        get currentYear() { return +this.current.slice(0, 4); },
                        isOff(m) { return this.ym(m) < $wire.firstMonth || this.ym(m) > this.current; },
                        toggle() { this.year = +$wire.selectedMonth.slice(0, 4); this.open = ! this.open; },
                        pick(m) { if (this.isOff(m)) return; this.open = false; $wire.selectMonth(this.ym(m)); },
                    }"
                    x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                    <div class="pi-monthnav pi-monthnav--lg">
                        <button type="button" wire:click="previousMonth" @disabled(! $canGoBack) aria-label="{{ __('Previous month') }}"><i class="fa-solid fa-chevron-left"></i></button>
                        <button type="button" class="pi-monthnav-trigger" x-on:click="toggle()" aria-haspopup="true" x-bind:aria-expanded="open">
                            {{ $month['month'] }}<i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <button type="button" wire:click="nextMonth" @disabled(! $canGoForward) aria-label="{{ __('Next month') }}"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <div class="pi-hk-monthpick" x-show="open" x-cloak x-transition.opacity>
                        <div class="pi-hk-monthpick-year">
                            <button type="button" x-on:click="year--" x-bind:disabled="year <= firstYear" aria-label="{{ __('Previous year') }}"><i class="fa-solid fa-chevron-left"></i></button>
                            <span x-text="year"></span>
                            <button type="button" x-on:click="year++" x-bind:disabled="year >= currentYear" aria-label="{{ __('Next year') }}"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                        <div class="pi-hk-monthpick-grid">
                            <template x-for="(name, i) in names" :key="i">
                                <button type="button" x-text="name" x-on:click="pick(i + 1)" x-bind:disabled="isOff(i + 1)"
                                    x-bind:class="{ 'is-active': ym(i + 1) === $wire.selectedMonth }"></button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- List --}}
            <div x-show="view === 'list'" class="pi-hk-scroll">
                <div class="pi-hk-table">
                    <div class="pi-grid {{ $colsClass }} pi-entries-thead">
                        <div>{{ __('Date') }}</div>
                        <div class="pi-num">{{ __('Opening') }}</div>
                        <div class="pi-num is-in">{{ __('Received') }}</div>
                        <div class="pi-num is-out">{{ __('Consumed') }}</div>
                        <div class="pi-num">{{ __('Correction') }}</div>
                        <div class="pi-num is-stock">{{ __('In Stock') }}</div>
                        @if ($canUpdate)
                            <div></div>
                        @endif
                    </div>

                    @forelse ($month['days'] as $row)
                        <div class="pi-grid {{ $colsClass }} pi-entry" wire:key="day-{{ $row['date'] }}">
                            <div class="pi-hk-date">
                                <span>{{ $day($row['date']) }}</span>
                                @if ($row['is_yesterday'])
                                    <span class="pi-tag pi-tag--latest">{{ __('Yesterday') }}</span>
                                @endif
                                @if ($row['pending'])
                                    <span class="pi-tag pi-tag--pending">{{ __('Pending') }}</span>
                                @elseif (! $row['has_row'])
                                    <span class="pi-tag pi-tag--inactive">{{ __('No record') }}</span>
                                @endif
                            </div>
                            @if ($row['has_row'])
                                <div class="pi-num is-muted">{{ $num($row['opening']) }}</div>
                                <div class="pi-num">{{ round($row['received']) != 0 ? $num($row['received']) : '—' }}</div>
                                <div class="pi-num">{{ round($row['consumed']) != 0 ? $num($row['consumed']) : '—' }}</div>
                                <div class="pi-num pi-hk-corr">
                                    @if (round($row['correction']) != 0)
                                        <span class="{{ $row['correction'] > 0 ? 'is-stock' : 'is-red' }}">{{ $signed($row['correction']) }}</span>
                                    @else
                                        <span class="is-faint">—</span>
                                    @endif
                                    @if ($row['comment'])
                                        <span class="pi-hk-note" title="{{ $row['comment'] }}">{{ $row['comment'] }}</span>
                                    @endif
                                </div>
                                <div class="pi-num pi-entry-qty {{ round($row['stock']) < 0 ? 'is-red' : '' }}">{{ $num($row['stock']) }}</div>
                            @else
                                <div class="pi-num is-faint">—</div>
                                <div class="pi-num is-faint">—</div>
                                <div class="pi-num is-faint">—</div>
                                <div class="pi-num is-faint">—</div>
                                <div class="pi-num is-faint">—</div>
                            @endif
                            @if ($canUpdate)
                                <div class="pi-entry-actions">
                                    @if ($row['date'] === $latestDate)
                                        <button type="button" wire:click="editLatest('{{ $row['date'] }}')" class="pi-act pi-act--icon" title="{{ __('Edit') }}" aria-label="{{ __('Edit :date', ['date' => $day($row['date'])]) }}"><i class="fa-regular fa-pen-to-square"></i></button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="pi-entries-empty">
                            <span class="pi-empty-title">{{ __('No days yet this month') }}</span>
                            <span class="pi-empty-text">{{ __('Each day is added the morning after.') }}</span>
                        </div>
                    @endforelse

                    @if ($month['days']->isNotEmpty())
                        <div class="pi-grid {{ $colsClass }} pi-hk-total">
                            <div>{{ __('Month total') }}</div>
                            <div></div>
                            <div class="pi-num">{{ $num($month['totals']['received']) }}</div>
                            <div class="pi-num">{{ $num($month['totals']['consumed']) }}</div>
                            <div class="pi-num">{{ round($month['totals']['correction']) != 0 ? $signed($month['totals']['correction']) : '—' }}</div>
                            <div class="pi-num {{ round($month['totals']['stock']) < 0 ? 'is-red' : '' }}">{{ $num($month['totals']['stock']) }}</div>
                            @if ($canUpdate)
                                <div></div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Chart --}}
            <div x-show="view === 'chart'" x-cloak class="pi-hk-chart"
                wire:key="husk-chart-{{ md5(json_encode($chart)) }}"
                x-data="huskStockChart(@js($chart), @js($canUpdate))">
                <div class="pi-card-head">
                    <div class="pi-legend">
                        <template x-for="series in legend" :key="series.key">
                            <button type="button" class="pi-chip" x-on:click="sv[series.key] = ! sv[series.key]">
                                <span class="pi-cb" :class="sv[series.key] ? 'is-on' : ''" :style="'--pi-cb:' + series.color" x-text="sv[series.key] ? '✓' : ''"></span>
                                <span x-text="series.label"></span>
                            </button>
                        </template>
                    </div>
                    <span class="pi-card-meta" x-show="last > 0" x-text="pin !== null ? @js(__('Day pinned · click it again or ✕ to unpin')) : @js(__('Hover to inspect a day · click to pin it'))"></span>
                </div>

                <template x-if="last === 0">
                    <div class="pi-chart-none">{{ __('No days yet this month. Each day is added the morning after.') }}</div>
                </template>

                <div class="pi-charts" x-show="last > 0" x-on:mouseleave="hov = null">
                    <template x-if="active">
                        <div class="pi-tip" :style="'left:' + tipLeft + ';transform:' + tipShift">
                            <div class="pi-tip-head">
                                <span x-text="dayLabel(h)"></span>
                                <template x-if="pin !== null"><button type="button" x-on:click="pin = null">✕</button></template>
                            </div>
                            <template x-for="row in tipRows" :key="row.label">
                                <div class="pi-tip-row"><span class="pi-tip-swatch" :style="'background:' + row.color"></span><span x-text="row.label"></span><span x-text="row.value"></span></div>
                            </template>
                            <template x-if="tipNote">
                                <div class="pi-hk-tip-note" x-text="tipNote"></div>
                            </template>
                            <template x-if="pin !== null && canEdit && data.editable[h]">
                                <a href="#" x-on:click.prevent="$wire.editLatest(data.dates[h])">{{ __('Edit this day') }} ›</a>
                            </template>
                        </div>
                    </template>

                    <template x-if="sv.stock">
                        <div style="display: contents">
                            <div class="pi-chart-label pi-hk-chart-label">
                                <span class="is-stock">{{ __('IN STOCK · CFT') }}</span>
                                <span class="pi-hk-key"><span class="pi-hk-key-dot"></span>{{ __('CORRECTION') }}</span>
                            </div>
                            <div class="pi-chart">
                                <svg viewBox="0 0 1000 206" x-html="stockSvg" x-on:mousemove="hover($event)" x-on:click="togglePin($event)" style="cursor: pointer"></svg>
                                <template x-for="tick in a1.ticks" :key="tick.label + tick.y"><div class="pi-axis-label" :style="'top:' + tick.pct" x-text="tick.label"></div></template>
                            </div>
                        </div>
                    </template>
                    <template x-if="sv.received || sv.consumed">
                        <div style="display: contents">
                            <div class="pi-chart-label is-muted">{{ __('RECEIVED / CONSUMED · CFT') }}</div>
                            <div class="pi-chart">
                                <svg viewBox="0 0 1000 116" x-html="moveSvg" x-on:mousemove="hover($event)" x-on:click="togglePin($event)" style="cursor: pointer"></svg>
                                <template x-for="tick in a2.ticks" :key="tick.label + tick.y"><div class="pi-axis-label" :style="'top:' + tick.pct" x-text="tick.label"></div></template>
                            </div>
                        </div>
                    </template>
                    <template x-if="! sv.stock && ! sv.received && ! sv.consumed">
                        <div class="pi-chart-none">{{ __('Tick at least one series above to see the chart.') }}</div>
                    </template>
                    <div class="pi-xaxis">
                        <template x-for="label in xLabels" :key="label.day"><div :style="'color:' + label.fill" x-text="label.day"></div></template>
                    </div>
                </div>
            </div>

            <div wire:loading.flex wire:target="previousMonth, nextMonth, selectMonth" class="pi-hk-loading">{{ __('Loading…') }}</div>
        </div>
    </div>

    {{-- Update Stock --}}
    @if ($formRecord)
        @php
            $formOpening = round((float) $formRecord->opening_stock, 2);
            $formClosing = round((float) $formRecord->closing_stock, 2);
        @endphp
        <div class="pi-modal-backdrop" wire:key="husk-form-{{ $formRecord->id }}">
            <form wire:submit="updateStock" class="pi-modal pi-modal--form"
                x-data="{
                    opening: {{ $formOpening }},
                    consumed: {{ round($formConsumed, 2) }},
                    closing: {{ $formClosing }},
                    fmt(v) { return Math.round(v).toLocaleString('en-IN'); },
                    get received() { return Number($wire.husksReceived) || 0; },
                    get signed() { const amount = Number($wire.correctionAmount) || 0; return $wire.correctionType === 'deduct' ? -amount : amount; },
                    get after() { return this.opening + this.received + this.signed - this.consumed; },
                }">
                <div class="pi-modal-head">
                    <span class="pi-modal-title">{{ __('Update Stock') }}</span>
                    <span class="pi-modal-sub">{{ $day($formRecord->date->toDateString()) }} · {{ __('Opening :cft cft', ['cft' => $num($formOpening)]) }}</span>
                </div>

                <label class="pi-field">
                    {{ __('Husks received') }}
                    <div class="pi-qty">
                        <input type="number" inputmode="decimal" min="0" step="any" autocomplete="off" placeholder="0"
                            wire:model="husksReceived" x-init="$el.focus()" x-on:focus="$el.select()">
                        <span class="pi-qty-unit">cft</span>
                    </div>
                    @if ($formLastEntry)
                        <span class="pi-hk-hint">{{ __('Last entry: :cft cft on :date', ['cft' => $num($formLastEntry->husks_received), 'date' => $day($formLastEntry->date->toDateString())]) }}</span>
                    @endif
                    @error('husksReceived') <span class="pi-error">{{ $message }}</span> @enderror
                </label>

                <div class="pi-field">
                    <span>{{ __('Stock correction') }} <span class="pi-hk-optional">{{ __('(optional)') }}</span></span>
                    <div class="pi-hk-correction">
                        <div class="pi-seg">
                            <button type="button" class="pi-seg-item pi-seg-item--lg" x-bind:class="{ 'is-active': $wire.correctionType !== 'deduct' }" x-on:click="$wire.correctionType = 'add'">{{ __('Add') }}</button>
                            <button type="button" class="pi-seg-item pi-seg-item--lg" x-bind:class="{ 'is-active': $wire.correctionType === 'deduct' }" x-on:click="$wire.correctionType = 'deduct'">{{ __('Deduct') }}</button>
                        </div>
                        <div class="pi-qty pi-qty--sm">
                            <input type="number" inputmode="decimal" min="0" step="any" autocomplete="off" placeholder="0"
                                wire:model="correctionAmount" x-on:focus="$el.select()">
                            <span class="pi-qty-unit">cft</span>
                        </div>
                    </div>
                    @error('correctionAmount') <span class="pi-error">{{ $message }}</span> @enderror
                    <input type="text" wire:model="correctionComment" maxlength="150" placeholder="{{ __('Reason (optional)') }}" class="pi-hk-input">
                    @error('correctionComment') <span class="pi-error">{{ $message }}</span> @enderror
                </div>

                <div class="pi-note">
                    <span class="pi-note-label">{{ __('In stock after saving') }}</span>
                    <span class="pi-note-value" :class="{ 'is-red': Math.round(after) < 0 }" x-text="fmt(closing) + ' → ' + fmt(after) + ' cft'"></span>
                    <span class="pi-note-text">{{ __('Consumed that day: :cft cft, from pith production', ['cft' => $num($formConsumed)]) }}</span>
                    <template x-if="Math.round(after) < 0">
                        <span class="pi-note-text is-red">{{ __('This leaves stock negative. Check husks received or add a correction.') }}</span>
                    </template>
                </div>

                <div class="pi-modal-actions">
                    <button type="button" wire:click="closeUpdateModal" class="pi-btn pi-btn--lg pi-btn--muted">{{ __('Cancel') }}</button>
                    <button type="submit" class="pi-btn pi-btn--lg pi-btn--solid pi-btn--maroon" wire:loading.attr="disabled" wire:target="updateStock">{{ __('Save') }}</button>
                </div>
            </form>
        </div>
    @endif

    <div class="pi-toast" x-cloak x-show="toast" x-transition.opacity x-bind:class="{ 'is-error': toastError }">
        <span x-text="toast"></span>
        <button type="button" x-on:click="toast = null">{{ __('OK') }}</button>
    </div>
</div>

@push('head')
    <link rel="stylesheet" href="@assetVersion('css/pith-inventory.css')">
    <script>
        /**
         * Chart state for the Husk Inventory page, on the same geometry as the Pith Inventory
         * History charts: a 1000-wide viewBox, a 60px left gutter for axis labels and one slot per
         * day of the month. Husk stock can go negative, so the axes run from below zero when it does.
         * Defined in <head> so it exists before Alpine starts.
         */
        window.huskStockChart = function (data, canEdit) {
            const SERIES = [
                { key: 'stock', label: @js(__('In Stock')), color: '#2E7359' },
                { key: 'received', label: @js(__('Received')), color: '#8B2323' },
                { key: 'consumed', label: @js(__('Consumed')), color: '#CC7A1E' },
            ];
            const CORRECTION = '#6B4FA0';

            return {
                data,
                canEdit,
                legend: SERIES,
                sv: { stock: true, received: true, consumed: true },
                hov: null,
                pin: null,

                get last() { return this.data.dates.length; },
                get bw() { return 924 / this.data.daysInMonth; },
                X(i) { return 60 + (i + 0.5) * this.bw; },
                n(v) { return Math.round(v).toLocaleString('en-IN'); },
                signed(v) { return (Math.round(v) > 0 ? '+' : Math.round(v) < 0 ? '−' : '') + this.n(Math.abs(v)); },
                sh(v) {
                    const a = Math.abs(v), sign = v < 0 ? '−' : '';
                    return sign + (a >= 100000 ? (+(a / 100000).toFixed(2)) + 'L' : a >= 1000 ? (+(a / 1000).toFixed(1)) + 'K' : String(Math.round(a)));
                },

                axis(values, top, bottom, height) {
                    const lo = Math.min(0, ...values);
                    // A span of at least 4 keeps an empty or tiny series on whole-number ticks.
                    const hi = Math.max(0, ...values, lo + 4);
                    const raw = (hi - lo) / 4;
                    const p = Math.pow(10, Math.floor(Math.log10(raw)));
                    const step = [1, 2, 2.5, 5, 10].map((f) => f * p).find((v) => v >= raw);
                    const floor = Math.floor(lo / step) * step;
                    const ceiling = Math.ceil(hi / step) * step;
                    const count = Math.max(1, Math.round((ceiling - floor) / step));
                    const Y = (v) => bottom - ((v - floor) / (ceiling - floor)) * (bottom - top);
                    const ticks = Array.from({ length: count + 1 }, (_, j) => {
                        const v = floor + step * j;
                        return { y: Y(v).toFixed(1), pct: (Y(v) / height * 100).toFixed(2) + '%', label: this.sh(v) };
                    });

                    return { Y, ticks, below: floor < 0 };
                },
                path(values, Y) { return values.map((v, i) => (i ? 'L' : 'M') + this.X(i).toFixed(1) + ' ' + Y(v).toFixed(1)).join(' '); },

                get h() {
                    if (this.pin !== null && this.pin < this.last) return this.pin;
                    if (this.hov !== null && this.hov < this.last) return this.hov;
                    return this.last - 1;
                },
                get active() { return this.pin !== null || this.hov !== null; },

                get a1() { return this.axis(this.data.series.stock, 12, 200, 206); },
                get a2() {
                    const values = [...(this.sv.received ? this.data.series.received : []), ...(this.sv.consumed ? this.data.series.consumed : [])];
                    return this.axis(values, 10, 110, 116);
                },

                grid(A) {
                    const zero = A.Y(0).toFixed(1);
                    return A.ticks.map((t) => `<line x1="60" x2="984" y1="${t.y}" y2="${t.y}" stroke="#EEEDF2"/>`).join('')
                        + (A.below ? `<line x1="60" x2="984" y1="${zero}" y2="${zero}" stroke="#B9B8C2"/>` : '');
                },
                band(height) {
                    return this.active ? `<rect x="${(60 + this.h * this.bw).toFixed(1)}" y="0" width="${this.bw.toFixed(1)}" height="${height}" fill="#F1EFF5"/>` : '';
                },
                guide(height) {
                    const x = this.X(this.h).toFixed(1);
                    return `<line x1="${x}" x2="${x}" y1="0" y2="${height}" stroke="#1B1B1F" stroke-dasharray="3 3" stroke-opacity=".35"/>`;
                },
                dim(i) { return this.active && i !== this.h ? '.35' : '1'; },

                get stockSvg() {
                    if (! this.last) return '';
                    const A = this.a1, values = this.data.series.stock, line = this.path(values, A.Y), zero = A.Y(0).toFixed(1);
                    const area = line + ' L' + this.X(this.last - 1).toFixed(1) + ' ' + zero + ' L' + this.X(0).toFixed(1) + ' ' + zero + ' Z';
                    const marks = this.data.series.correction.map((c, i) => Math.round(c) !== 0
                        ? `<circle cx="${this.X(i).toFixed(1)}" cy="${A.Y(values[i]).toFixed(1)}" r="4.5" fill="${CORRECTION}" stroke="#fff" stroke-width="1.5"/>`
                        : '').join('');
                    return this.band(206) + this.grid(A)
                        + `<path d="${area}" fill="#4DA283" fill-opacity=".14"/>`
                        + `<path d="${line}" fill="none" stroke="#2E7359" stroke-width="2.5" stroke-linejoin="round"/>`
                        + marks
                        + `<circle cx="${this.X(this.h).toFixed(1)}" cy="${A.Y(values[this.h] || 0).toFixed(1)}" r="5" fill="#fff" stroke="#2E7359" stroke-width="2.5"/>`
                        + this.guide(206);
                },
                get moveSvg() {
                    if (! this.last) return '';
                    const A = this.a2, bw = this.bw, zero = A.Y(0);
                    let bars = '';
                    const bar = (x, v, color, i) => `<rect x="${x.toFixed(1)}" y="${A.Y(v).toFixed(1)}" width="${(bw * 0.34).toFixed(1)}" height="${(zero - A.Y(v)).toFixed(1)}" rx="2" fill="${color}" opacity="${this.dim(i)}"/>`;
                    if (this.sv.received) this.data.series.received.forEach((v, i) => { if (v > 0) bars += bar(this.X(i) - bw * 0.36, v, '#8B2323', i); });
                    if (this.sv.consumed) this.data.series.consumed.forEach((v, i) => { if (v > 0) bars += bar(this.X(i) + bw * 0.02, v, '#CC7A1E', i); });
                    return this.band(116) + this.grid(A) + bars + this.guide(116);
                },

                get tipLeft() { return (this.X(this.h) / 10).toFixed(2) + '%'; },
                get tipShift() { return this.X(this.h) > 640 ? 'translateX(calc(-100% - 14px))' : 'translateX(14px)'; },
                get tipRows() {
                    const rows = SERIES.filter((s) => this.sv[s.key]).map((s) => ({ label: s.label, color: s.color, value: this.n(this.data.series[s.key][this.h] || 0) + ' cft' }));
                    const correction = this.data.series.correction[this.h] || 0;
                    if (Math.round(correction) !== 0) {
                        rows.push({ label: @js(__('Correction')), color: CORRECTION, value: this.signed(correction) + ' cft' });
                    }
                    return rows;
                },
                get tipNote() {
                    if (! this.data.recorded[this.h]) return @js(__('No record · stock carried from the day before'));
                    return this.data.comments[this.h] || '';
                },
                get xLabels() {
                    return Array.from({ length: this.data.daysInMonth }, (_, i) => ({ day: String(i + 1), fill: i < this.last ? '#6B6A72' : '#C9C8D0' }));
                },
                dayLabel(i) { return this.data.labels[i]; },

                indexAt(event) {
                    const rect = event.currentTarget.getBoundingClientRect();
                    const x = (event.clientX - rect.left) / rect.width * 1000;
                    const i = Math.floor((x - 60) / this.bw);
                    return i >= 0 && i < this.last ? i : null;
                },
                hover(event) { const i = this.indexAt(event); if (i !== null) this.hov = i; },
                togglePin(event) { const i = this.indexAt(event); if (i !== null) this.pin = this.pin === i ? null : i; },
            };
        };
    </script>
@endpush
