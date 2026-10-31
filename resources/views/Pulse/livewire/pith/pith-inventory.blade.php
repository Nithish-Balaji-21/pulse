@php
    $num = function ($value) {
        $value = round((float) $value);

        return \App\Services\NumberFormatterService::formatToIndianFormat($value == 0 ? 0 : $value, false, 0);
    };
    $pct = fn ($out, $in) => $in > 0 ? (int) min(100, max(0, round($out / $in * 100))) : 0;
    $isFull = ! $selectedLocationId;
    $cycles = fn (int $count) => $count === 1 ? __('1 cycle') : __(':count cycles', ['count' => $count]);
@endphp

<div class="pi"
    x-data="{ toast: null, toastError: false, timer: null,
        showToast(detail) { this.toast = detail.message; this.toastError = !! detail.error; clearTimeout(this.timer); this.timer = setTimeout(() => this.toast = null, 5000); } }"
    x-on:pi-toast.window="showToast($event.detail)">

    <div class="pi-split {{ $selectedLocationId ? 'has-panel' : '' }}">
        <div class="pi-page">
            <div class="pi-head">
                <div class="pi-head-text">
                    <span class="pi-title">{{ __('Pith Inventory') }}</span>
                    <span class="pi-subtitle">{{ __('Stock and watering across all locations') }}</span>
                </div>
                <div class="pi-actions">
                    @if ($canTransfer)
                        <button type="button" x-on:click="$dispatch('open-pith-transfer')" class="pi-btn pi-btn--transfer">⇄ {{ __('Transfer Pith') }}</button>
                    @endif
                    <button type="button" wire:click="openExportModal" class="pi-btn"><i class="fa-solid fa-file-export"></i>{{ __('Filter & Export') }}</button>
                    <a href="{{ route('pith.inventory.history', ['team' => $teamId]) }}" class="pi-btn"><i class="fa-solid fa-clock-rotate-left"></i>{{ __('Inventory History') }}</a>
                </div>
            </div>

            <div class="pi-card pi-card--pad">
                <div class="pi-card-head">
                    <span class="pi-card-title">{{ __('Total Pith') }}</span>
                    <span class="pi-card-meta">{{ __('All locations · cft') }}</span>
                </div>
                <div class="pi-bar {{ $totalOffloaded > 0 ? '' : 'is-empty' }}"><span style="width: {{ $pct($totalTaken, $totalOffloaded) }}%"></span></div>
                <div class="pi-stats">
                    <div class="pi-stat"><span class="pi-stat-label is-in">{{ __('Inward') }}</span><span class="pi-stat-value {{ $isFull ? '' : 'pi-stat-value--sm' }}">{{ $num($totalOffloaded) }}</span></div>
                    <div class="pi-stat"><span class="pi-stat-label is-out">{{ __('Outward') }}</span><span class="pi-stat-value {{ $isFull ? '' : 'pi-stat-value--sm' }}">{{ $num($totalTaken) }}</span></div>
                    <div class="pi-stat"><span class="pi-stat-label is-stock">{{ __('In Stock') }}</span><span class="pi-stat-value {{ $isFull ? '' : 'pi-stat-value--sm' }}">{{ $num($totalInStock) }}</span></div>
                    <div class="pi-stat">
                        <span class="pi-stat-label is-water">{{ __('Watered') }}</span>
                        <span class="pi-stat-value is-water-ink {{ $isFull ? '' : 'pi-stat-value--sm' }}">{{ $num($totalWaterLitres) }} L</span>
                        @if ($totalWaterCycles > 0)
                            <span class="pi-stat-sub">{{ $cycles($totalWaterCycles) }}</span>
                        @endif
                    </div>
                </div>
                <div class="pi-card-foot">{{ __('Overdrawn') }}: <strong>{{ $num($totalOverdrawn) }} cft</strong></div>
            </div>

            <div class="pi-card pi-card--clip">
                <div class="pi-grid {{ $isFull ? 'pi-cols-full' : 'pi-cols-compact' }} pi-thead">
                    <div class="pi-thead-loc">
                        {{ __('Location') }}
                        @if ($hasGroups)
                            <button type="button" wire:click="toggleAllGroups" class="pi-link-btn">{{ $allGroupsExpanded ? __('Collapse all') : __('Expand all') }}</button>
                        @endif
                    </div>
                    @if ($isFull)
                        <div class="pi-num is-in">{{ __('Inward') }}</div>
                        <div class="pi-num is-out pi-out-head">{{ __('Outward') }}</div>
                    @endif
                    <div class="pi-num is-stock">{{ __('In Stock') }}</div>
                    <div class="pi-num is-water">{{ __('Watered') }}</div>
                    @if ($isFull)
                        <div class="pi-num">{{ __('Last Watered') }}</div>
                    @endif
                </div>

                @if ($hasGroups)
                    @foreach ($groups as $group)
                        @php $isExpanded = in_array($group['id'], $expandedGroups); @endphp
                        @include('pulse::livewire.pith.partials.inventory-group-row', ['group' => $group, 'isExpanded' => $isExpanded])
                        @if ($isExpanded)
                            @foreach ($group['locations'] as $location)
                                @include('pulse::livewire.pith.partials.inventory-location-row', ['location' => $location, 'indented' => true])
                            @endforeach
                        @endif
                    @endforeach
                @else
                    @foreach ($locations as $location)
                        @include('pulse::livewire.pith.partials.inventory-location-row', ['location' => $location, 'indented' => false])
                    @endforeach
                @endif

                @if ($locations->isEmpty())
                    <div class="pi-empty">
                        <span class="pi-empty-title">{{ __('No locations yet') }}</span>
                        <span class="pi-empty-text">{{ __('Add pith locations in Settings to start tracking inventory.') }}</span>
                    </div>
                @endif
            </div>
        </div>

        @if ($selectedLocationId)
            <aside class="pi-panel">
                @livewire(\App\Livewire\Pulse\Pith\PithLocationLoads::class, ['locationId' => $selectedLocationId, 'teamId' => $teamId], key('location-panel-'.$selectedLocationId))
            </aside>
        @endif
    </div>

    {{-- Filter & Export --}}
    @if ($showExportModal)
        @php
            $pdfUrl = $exportReportData ? route('pith.inventory.export.pdf', [
                'team' => $teamId,
                'start_date' => $exportStartDate,
                'end_date' => $exportEndDate,
                'watering' => $exportIncludeWatering ? 1 : 0,
                'empty' => $exportIncludeEmpty ? 1 : 0,
            ]) : null;
        @endphp
        <div class="pi-modal-backdrop" wire:key="export-modal">
            <div class="pi-export">
                <div class="pi-export-bar">
                    <div>
                        <span class="pi-modal-title">{{ __('Filter & Export') }}</span>
                        <span class="pi-modal-sub">{{ __('Opening, inward, outward, closing and watering per location. The export matches the preview.') }}</span>
                    </div>
                    <button type="button" wire:click="closeExportModal" class="pi-icon-btn" aria-label="{{ __('Close') }}">✕</button>
                </div>

                <div class="pi-export-tools">
                    <div wire:ignore
                        x-data
                        x-init="initReportsRangeSelector({
                            startDate: @js($exportStartDate),
                            endDate: @js($exportEndDate),
                            onApply: (s, e, p) => $wire.call('applyExportRange', s, e, p)
                        })">
                        @include('pulse::dashboard.date-range-selector', [
                            'presets' => [
                                'yesterday' => __('Yesterday'),
                                '7d' => __('Last 7 days'),
                                '1m' => __('This month'),
                                'custom' => __('Custom'),
                            ],
                            'default' => 'yesterday',
                        ])
                    </div>

                    <button type="button" wire:click="toggleExportWatering" class="pi-check">
                        <span class="pi-cb {{ $exportIncludeWatering ? 'is-on' : '' }}" style="--pi-cb: var(--pi-blue)">{{ $exportIncludeWatering ? '✓' : '' }}</span>{{ __('Watering column') }}
                    </button>
                    <button type="button" wire:click="toggleExportEmpty" class="pi-check">
                        <span class="pi-cb {{ $exportIncludeEmpty ? 'is-on' : '' }}">{{ $exportIncludeEmpty ? '✓' : '' }}</span>{{ __('Empty locations') }}
                    </button>

                    @if ($exportReportData)
                        <div class="pi-export-buttons">
                            <button type="button" id="pithExportImageBtn" class="pi-btn pi-btn--sm"
                                data-filename="pith-inventory-{{ $exportStartDate }}-to-{{ $exportEndDate }}.png"><i class="fa-solid fa-image"></i>{{ __('Export image') }}</button>
                            <a href="{{ $pdfUrl }}" target="_blank" rel="noopener" class="pi-btn pi-btn--sm pi-btn--solid pi-btn--maroon"><i class="fa-solid fa-file-pdf"></i>{{ __('Export PDF') }}</a>
                        </div>
                    @endif
                </div>

                <div class="pi-export-body">
                    @error('exportStartDate') <p class="pi-error">{{ $message }}</p> @enderror
                    @error('exportEndDate') <p class="pi-error">{{ $message }}</p> @enderror

                    <div wire:loading.flex wire:target="setExportPreset, applyFilter, toggleExportEmpty, exportStartDate, exportEndDate" class="pi-export-loading" style="justify-content: center">{{ __('Loading…') }}</div>

                    <div wire:loading.remove wire:target="setExportPreset, applyFilter, toggleExportEmpty, exportStartDate, exportEndDate">
                        @if ($exportReportData)
                            <div class="pi-export-sheet" id="pith-export-report-render">
                                @include('pulse::livewire.pith.exports.inventory-report', ['report' => $exportReportData, 'teamName' => $teamName, 'showWatering' => $exportIncludeWatering])
                            </div>

                            {{-- Not shown; only its innerHTML is read when "Export image" is clicked. --}}
                            <div id="pith-export-report-image-source" style="display: none;">
                                @include('pulse::livewire.pith.exports.inventory-report-image', ['report' => $exportReportData, 'teamName' => $teamName, 'showWatering' => $exportIncludeWatering])
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- The popup also edits transfers, so it is there for anyone who can create or edit one. --}}
    @if ($canTransfer || $canEditTransfer)
        @livewire(\App\Livewire\Pulse\Pith\PithStockTransfer::class, ['teamId' => $teamId], key('pith-stock-transfer'))
    @endif

    <div class="pi-toast" x-cloak x-show="toast" x-transition.opacity x-bind:class="{ 'is-error': toastError }">
        <span x-text="toast"></span>
        <button type="button" x-on:click="toast = null">{{ __('OK') }}</button>
    </div>
</div>

@push('head')
    <link rel="stylesheet" href="@assetVersion('css/pith-inventory.css')">
    <link rel="stylesheet" href="@assetVersion('css/daterangepicker.css')">
    <link rel="stylesheet" href="@assetVersion('css/date-range-selector.css')">
@endpush

@push('scripts')
<script src="@assetVersion('js/daterangeselector.js')"></script>
<script src="@assetVersion('js/html2canvas.min.js')"></script>
<script>
    (function () {
        /**
         * The report is rendered into a same-origin iframe that never loads the page's
         * Tailwind stylesheet: its palette is authored in oklch(), which html2canvas's CSS
         * parser throws on. Only the report's own inline <style> is present to parse. Fonts
         * are awaited so the capture uses Figtree rather than the fallback.
         */
        function exportPithReportAsImage(button) {
            const source = document.getElementById('pith-export-report-image-source');
            if (!source || typeof html2canvas === 'undefined') {
                return;
            }

            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed';
            iframe.style.top = '-99999px';
            iframe.style.left = '-99999px';
            iframe.style.width = '980px';
            iframe.style.height = '2000px';
            document.body.appendChild(iframe);

            new Promise(function (resolve) {
                iframe.onload = resolve;
                iframe.srcdoc = '<!DOCTYPE html><html><body style="margin:0;">' + source.innerHTML + '</body></html>';
            }).then(function () {
                const doc = iframe.contentDocument;
                return (doc.fonts ? doc.fonts.ready : Promise.resolve()).then(function () {
                    return html2canvas(doc.body.firstElementChild || doc.body, { backgroundColor: '#ffffff', scale: 2 });
                });
            }).then(function (canvas) {
                const link = document.createElement('a');
                link.download = button.dataset.filename || 'pith-inventory.png';
                link.href = canvas.toDataURL('image/png');
                document.body.appendChild(link);
                link.click();
                link.remove();
            }).catch(function (err) {
                console.error('Pith inventory image export failed:', err);
            }).finally(function () {
                iframe.remove();
            });
        }

        // Delegated so the listener survives Livewire re-rendering the popup.
        if (!window.__pithExportImageBound) {
            window.__pithExportImageBound = true;
            document.addEventListener('click', function (event) {
                const imageBtn = event.target.closest('#pithExportImageBtn');
                if (imageBtn) {
                    exportPithReportAsImage(imageBtn);
                }
            });
        }
    })();
</script>
@endpush
