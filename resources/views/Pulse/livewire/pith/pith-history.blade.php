@php
    $flatList = count($locationGroups) === 1 && $locationGroups[0]['is_ungrouped'];
    $selectedCount = count($selectedLocations);
@endphp

<div class="pi">
    <div class="pi-page">
        <div class="pi-head pi-head--end">
            <div class="pi-head-text" style="min-width: 240px">
                <div class="pi-crumb">
                    <a href="{{ route('pith.inventory', ['team' => $teamId]) }}">{{ __('Pith Inventory') }}</a><span>›</span><span>{{ __('History') }}</span>
                </div>
                <span class="pi-title">{{ __('Inventory History') }}</span>
                <span class="pi-subtitle">{{ __('Daily stock, inward, outward and watering') }}</span>
            </div>
            <div class="pi-monthnav">
                <button type="button" wire:click="previousMonth" @disabled(! $canGoBack) aria-label="{{ __('Previous month') }}">‹</button>
                <div class="pi-monthnav-label">{{ $chart['month'] }}</div>
                <button type="button" wire:click="nextMonth" @disabled(! $canGoForward) aria-label="{{ __('Next month') }}">›</button>
            </div>
        </div>

        <div wire:key="history-{{ md5(json_encode($chart)) }}"
            x-data="piHistoryChart(@js($chart), @js(route('pith.inventory', ['team' => $teamId])))"
            style="display: flex; flex-direction: column; gap: 16px">

            {{-- All locations --}}
            <div class="pi-card pi-card--pad-lg">
                <div class="pi-card-head">
                    <span class="pi-card-title">{{ __('All locations') }}</span>
                    <span class="pi-card-meta" x-text="pin !== null ? @js(__('Day pinned · click it again or ✕ to unpin')) : @js(__('Hover to inspect a day · click to pin it'))"></span>
                </div>

                <div class="pi-legend">
                    <template x-for="series in legend" :key="series.key">
                        <button type="button" class="pi-chip" x-on:click="sv[series.key] = ! sv[series.key]">
                            <span class="pi-cb" :class="sv[series.key] ? 'is-on' : ''" :style="'--pi-cb:' + series.color" x-text="sv[series.key] ? '✓' : ''"></span>
                            <span x-text="series.label"></span>
                        </button>
                    </template>
                </div>

                <template x-if="last === 0">
                    <div class="pi-chart-none">{{ __('No history for this month yet. Each day is added the morning after.') }}</div>
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
                            <template x-if="pin !== null">
                                <a :href="exportUrl">{{ __('Open this day in report') }} ›</a>
                            </template>
                        </div>
                    </template>

                    <template x-if="sv.stock">
                        <div style="display: contents">
                            <div class="pi-chart-label is-stock">{{ __('IN STOCK · CFT') }}</div>
                            <div class="pi-chart">
                                <svg viewBox="0 0 1000 206" x-html="stockSvg" x-on:mousemove="hover($event)" x-on:click="togglePin($event)" style="cursor: pointer"></svg>
                                <template x-for="tick in a1.ticks" :key="tick.label + tick.y"><div class="pi-axis-label" :style="'top:' + tick.pct" x-text="tick.label"></div></template>
                            </div>
                        </div>
                    </template>
                    <template x-if="sv.in || sv.out">
                        <div style="display: contents">
                            <div class="pi-chart-label is-muted">{{ __('INWARD / OUTWARD · CFT') }}</div>
                            <div class="pi-chart">
                                <svg viewBox="0 0 1000 116" x-html="moveSvg" x-on:mousemove="hover($event)" x-on:click="togglePin($event)" style="cursor: pointer"></svg>
                                <template x-for="tick in a2.ticks" :key="tick.label + tick.y"><div class="pi-axis-label" :style="'top:' + tick.pct" x-text="tick.label"></div></template>
                            </div>
                        </div>
                    </template>
                    <template x-if="sv.water">
                        <div style="display: contents">
                            <div class="pi-chart-label is-water">{{ __('WATERED · LITRES') }}</div>
                            <div class="pi-chart">
                                <svg viewBox="0 0 1000 86" x-html="waterSvg" x-on:mousemove="hover($event)" x-on:click="togglePin($event)" style="cursor: pointer"></svg>
                                <template x-for="tick in a3.ticks" :key="tick.label + tick.y"><div class="pi-axis-label" :style="'top:' + tick.pct" x-text="tick.label"></div></template>
                            </div>
                        </div>
                    </template>
                    <template x-if="! sv.stock && ! sv.in && ! sv.out && ! sv.water">
                        <div class="pi-chart-none">{{ __('Tick at least one series above to see the chart.') }}</div>
                    </template>
                    <div class="pi-xaxis">
                        <template x-for="label in xLabels" :key="label.day"><div :style="'color:' + label.fill" x-text="label.day"></div></template>
                    </div>
                </div>
            </div>

            <div class="pi-history-grid">
                {{-- Location picker --}}
                <div class="pi-card pi-card--clip">
                    <div class="pi-picker-head">
                        <div>
                            <span>{{ __('Locations') }}</span>
                            <span>{{ $selectedCount === 1 ? __('1 location selected') : __(':count locations selected', ['count' => $selectedCount]) }}</span>
                        </div>
                        <button type="button" wire:click="clearSelectedLocations" class="pi-link-btn pi-link-btn--md">{{ __('Clear') }}</button>
                    </div>
                    <div class="pi-picker-list">
                        @forelse ($locationGroups as $group)
                            @php
                                $isOpen = $flatList || in_array($group['id'], $expandedGroups, true);
                                $partial = $group['selectedCount'] > 0 && ! $group['allSelected'];
                            @endphp
                            @unless ($flatList)
                                <div class="pi-picker-group" wire:key="picker-group-{{ $group['id'] }}">
                                    <button type="button" wire:click="toggleGroupExpanded({{ $group['id'] }})" class="pi-picker-toggle {{ $isOpen ? 'is-open' : '' }}" aria-label="{{ $isOpen ? __('Collapse') : __('Expand') }}">
                                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" class="pi-picker-toggle-icon">
                                            <path d="M7 5l6 5-6 5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                    <div role="button" tabindex="0" wire:click="toggleGroupSelection({{ $group['id'] }})" class="pi-picker-group-name">
                                        <span class="pi-cb {{ $group['allSelected'] ? 'is-on' : ($partial ? 'is-part' : '') }}">{{ $group['allSelected'] ? '✓' : ($partial ? '–' : '') }}</span>
                                        <span>{{ $group['name'] }}</span>
                                        <span>{{ $group['selectedCount'] }}/{{ count($group['locationIds']) }}</span>
                                    </div>
                                </div>
                            @endunless
                            @if ($isOpen)
                                @foreach ($group['locations'] as $loc)
                                    @php
                                        $isOn = in_array((string) $loc['id'], $selectedLocations, true);
                                        $color = $selectionColors[(string) $loc['id']] ?? null;
                                    @endphp
                                    <div role="button" tabindex="0" wire:key="picker-loc-{{ $loc['id'] }}" wire:click="toggleLocation({{ $loc['id'] }})"
                                        class="pi-picker-row {{ $flatList ? 'pi-picker-row--flat' : '' }}">
                                        <span class="pi-cb {{ $isOn ? 'is-on' : '' }}" @if ($color) style="--pi-cb: {{ $color }}" @endif>{{ $isOn ? '✓' : '' }}</span>
                                        <span>{{ $loc['name'] }}</span>
                                        @unless ($loc['is_active'])
                                            <span class="pi-tag pi-tag--inactive">{{ __('Inactive') }}</span>
                                        @endunless
                                    </div>
                                @endforeach
                            @endif
                        @empty
                            <div class="pi-empty"><span class="pi-empty-text">{{ __('No locations yet.') }}</span></div>
                        @endforelse
                    </div>
                </div>

                {{-- Compare locations --}}
                <div class="pi-card pi-card--pad-lg" style="min-width: 0">
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap">
                        <div style="display: flex; flex-direction: column; gap: 1px; margin-right: auto">
                            <span class="pi-card-title">{{ __('Compare locations') }}</span>
                            <span class="pi-card-meta" x-text="last > 0 ? dayLabel(hC) + (! activeCompare ? ' · ' + @js(__('latest')) : '') + ' · ' + (om === 'water' ? @js(__('litres')) : 'cft') : ''"></span>
                        </div>
                        <div class="pi-seg">
                            <template x-for="metric in metrics" :key="metric.key">
                                <button type="button" class="pi-seg-item" :class="om === metric.key ? 'is-active' : ''" x-on:click="om = metric.key" x-text="metric.label"></button>
                            </template>
                        </div>
                    </div>

                    <template x-if="data.locations.length === 0">
                        <div class="pi-chart-none" style="padding: 60px 16px">{{ __('Tick locations on the left to compare them.') }}</div>
                    </template>
                    <template x-if="data.locations.length > 0">
                        <div style="display: flex; flex-direction: column; gap: 14px">
                            <div class="pi-legend">
                                <template x-for="location in data.locations" :key="location.id">
                                    <button type="button" class="pi-chip" x-on:click="$wire.toggleLocation(location.id)">
                                        <span class="pi-cb is-on" :style="'--pi-cb:' + location.color">✓</span>
                                        <span x-text="location.name"></span>
                                    </button>
                                </template>
                            </div>
                            <div class="pi-charts" x-show="last > 0" x-on:mouseleave="hovCompare = null">
                                <div class="pi-chart">
                                    <svg viewBox="0 0 1000 246" x-html="compareSvg" x-on:mousemove="hover($event, 'compare')" x-on:click="togglePin($event, 'compare')" style="cursor: pointer"></svg>
                                    <template x-for="tick in a4.ticks" :key="tick.label + tick.y"><div class="pi-axis-label" :style="'top:' + tick.pct" x-text="tick.label"></div></template>
                                </div>
                                <div class="pi-xaxis">
                                    <template x-for="label in xLabels" :key="label.day"><div :style="'color:' + label.fill" x-text="label.day"></div></template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>

@push('head')
    <link rel="stylesheet" href="@assetVersion('css/pith-inventory.css')">
    <script>
        /**
         * Chart state for the Inventory History page. Ported from the Claude Design source so
         * the geometry matches it: a 1000-wide viewBox, a 60px left gutter for axis labels and
         * one slot per day of the month. Defined in <head> so it exists before Alpine starts.
         */
        window.piHistoryChart = function (data, inventoryUrl) {
            const SERIES = [
                { key: 'stock', label: @js(__('In Stock')), color: '#2E7359', unit: ' cft' },
                { key: 'in', label: @js(__('Inward')), color: '#8B2323', unit: ' cft' },
                { key: 'out', label: @js(__('Outward')), color: '#CC7A1E', unit: ' cft' },
                { key: 'water', label: @js(__('Watered')), color: '#2F78A8', unit: ' L' },
            ];

            return {
                data,
                legend: SERIES,
                metrics: SERIES.map((s) => ({ key: s.key, label: s.label })),
                sv: { stock: true, in: true, out: true, water: true },
                hov: null,
                pin: null,
                hovCompare: null,
                pinCompare: null,
                om: 'stock',

                get last() { return this.data.dates.length; },
                get bw() { return 924 / this.data.daysInMonth; },
                X(i) { return 60 + (i + 0.5) * this.bw; },
                n(v) { return Math.round(v).toLocaleString('en-IN'); },
                sh(v) { return v >= 100000 ? (+(v / 100000).toFixed(2)) + 'L' : v >= 1000 ? (+(v / 1000).toFixed(1)) + 'K' : String(Math.round(v)); },
                max(values) { return values.length ? Math.max(0, ...values) : 0; },

                axis(mx, top, bottom, height) {
                    // A floor of 4 keeps an empty or tiny series on whole-number ticks (0–4)
                    // rather than 0.25 steps that all round to the same label.
                    mx = Math.max(mx, 4);
                    const raw = mx / 4;
                    const p = Math.pow(10, Math.floor(Math.log10(raw)));
                    const step = [1, 2, 2.5, 5, 10].map((f) => f * p).find((v) => v >= raw);
                    const count = Math.max(1, Math.ceil(mx / step));
                    const ceiling = step * count;
                    const Y = (v) => bottom - (v / ceiling) * (bottom - top);
                    const ticks = Array.from({ length: count + 1 }, (_, j) => ({
                        y: Y(step * j).toFixed(1),
                        pct: (Y(step * j) / height * 100).toFixed(2) + '%',
                        label: this.sh(step * j),
                    }));

                    return { Y, ticks };
                },
                path(values, Y) { return values.map((v, i) => (i ? 'L' : 'M') + this.X(i).toFixed(1) + ' ' + Y(v).toFixed(1)).join(' '); },

                get h() {
                    if (this.pin !== null && this.pin < this.last) return this.pin;
                    if (this.hov !== null && this.hov < this.last) return this.hov;
                    return this.last - 1;
                },
                get active() { return this.pin !== null || this.hov !== null; },

                /*
                 * Compare Locations tracks its own hover/pin index so mousing over the "All
                 * locations" charts above it never draws a guide line down here too.
                 */
                get hC() {
                    if (this.pinCompare !== null && this.pinCompare < this.last) return this.pinCompare;
                    if (this.hovCompare !== null && this.hovCompare < this.last) return this.hovCompare;
                    return this.last - 1;
                },
                get activeCompare() { return this.pinCompare !== null || this.hovCompare !== null; },

                get a1() { return this.axis(this.max(this.data.aggregate.stock), 12, 200, 206); },
                get a2() {
                    const values = [...(this.sv.in ? this.data.aggregate.in : []), ...(this.sv.out ? this.data.aggregate.out : [])];
                    return this.axis(this.max(values), 10, 110, 116);
                },
                get a3() { return this.axis(this.max(this.data.aggregate.water), 10, 80, 86); },
                get a4() { return this.axis(this.max(this.data.locations.flatMap((l) => l[this.om] || [])), 12, 240, 246); },

                grid(ticks) { return ticks.map((t) => `<line x1="60" x2="984" y1="${t.y}" y2="${t.y}" stroke="#EEEDF2"/>`).join(''); },
                band(height, index, active) {
                    return active ? `<rect x="${(60 + index * this.bw).toFixed(1)}" y="0" width="${this.bw.toFixed(1)}" height="${height}" fill="#F1EFF5"/>` : '';
                },
                guide(height, index) {
                    const x = this.X(index).toFixed(1);
                    return `<line x1="${x}" x2="${x}" y1="0" y2="${height}" stroke="#1B1B1F" stroke-dasharray="3 3" stroke-opacity=".35"/>`;
                },
                dim(i, index, active) { return active && i !== index ? '.35' : '1'; },

                get stockSvg() {
                    if (! this.last) return '';
                    const A = this.a1, values = this.data.aggregate.stock, line = this.path(values, A.Y);
                    const area = line + ' L' + this.X(this.last - 1).toFixed(1) + ' 200 L' + this.X(0).toFixed(1) + ' 200 Z';
                    return this.band(206, this.h, this.active) + this.grid(A.ticks)
                        + `<path d="${area}" fill="#4DA283" fill-opacity=".14"/>`
                        + `<path d="${line}" fill="none" stroke="#2E7359" stroke-width="2.5" stroke-linejoin="round"/>`
                        + `<circle cx="${this.X(this.h).toFixed(1)}" cy="${A.Y(values[this.h] || 0).toFixed(1)}" r="5" fill="#fff" stroke="#2E7359" stroke-width="2.5"/>`
                        + this.guide(206, this.h);
                },
                get moveSvg() {
                    if (! this.last) return '';
                    const A = this.a2, bw = this.bw;
                    let bars = '';
                    const bar = (x, v, color, i) => `<rect x="${x.toFixed(1)}" y="${A.Y(v).toFixed(1)}" width="${(bw * 0.34).toFixed(1)}" height="${(110 - A.Y(v)).toFixed(1)}" rx="2" fill="${color}" opacity="${this.dim(i, this.h, this.active)}"/>`;
                    if (this.sv.in) this.data.aggregate.in.forEach((v, i) => { if (v > 0) bars += bar(this.X(i) - bw * 0.36, v, '#8B2323', i); });
                    if (this.sv.out) this.data.aggregate.out.forEach((v, i) => { if (v > 0) bars += bar(this.X(i) + bw * 0.02, v, '#CC7A1E', i); });
                    return this.band(116, this.h, this.active) + this.grid(A.ticks) + bars + this.guide(116, this.h);
                },
                get waterSvg() {
                    if (! this.last) return '';
                    const A = this.a3, bw = this.bw;
                    const bars = this.data.aggregate.water.map((v, i) => v > 0
                        ? `<rect x="${(this.X(i) - bw * 0.3).toFixed(1)}" y="${A.Y(v).toFixed(1)}" width="${(bw * 0.6).toFixed(1)}" height="${(80 - A.Y(v)).toFixed(1)}" rx="2" fill="#2F78A8" opacity="${this.dim(i, this.h, this.active)}"/>`
                        : '').join('');
                    return this.band(86, this.h, this.active) + this.grid(A.ticks) + bars + this.guide(86, this.h);
                },
                get compareSvg() {
                    if (! this.last) return '';
                    const A = this.a4;
                    const lines = this.data.locations.map((l) => `<path d="${this.path(l[this.om] || [], A.Y)}" fill="none" stroke="${l.color}" stroke-width="2.5" stroke-linejoin="round"/>`).join('');
                    return this.grid(A.ticks) + lines + this.guide(246, this.hC);
                },

                get tipLeft() { return (this.X(this.h) / 10).toFixed(2) + '%'; },
                get tipShift() { return this.X(this.h) > 640 ? 'translateX(calc(-100% - 14px))' : 'translateX(14px)'; },
                get tipRows() {
                    return SERIES.filter((s) => this.sv[s.key]).map((s) => ({ label: s.label, color: s.color, value: this.n(this.data.aggregate[s.key][this.h] || 0) + s.unit }));
                },
                get xLabels() {
                    return Array.from({ length: this.data.daysInMonth }, (_, i) => ({ day: String(i + 1), fill: i < this.last ? '#6B6A72' : '#C9C8D0' }));
                },
                get exportUrl() { return inventoryUrl + '?export=' + this.data.dates[this.h]; },
                dayLabel(i) { return (i + 1) + ' ' + this.data.monthShort + ' ' + this.data.year; },

                indexAt(event) {
                    const rect = event.currentTarget.getBoundingClientRect();
                    const x = (event.clientX - rect.left) / rect.width * 1000;
                    const i = Math.floor((x - 60) / this.bw);
                    return i >= 0 && i < this.last ? i : null;
                },
                hover(event, target = 'all') {
                    const i = this.indexAt(event);
                    if (i === null) return;
                    if (target === 'compare') { this.hovCompare = i; } else { this.hov = i; }
                },
                togglePin(event, target = 'all') {
                    const i = this.indexAt(event);
                    if (i === null) return;
                    if (target === 'compare') { this.pinCompare = this.pinCompare === i ? null : i; }
                    else { this.pin = this.pin === i ? null : i; }
                },
            };
        };
    </script>
@endpush
