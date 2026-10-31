{{--
    Source for the "Export image" button in the Pith Inventory Filter & Export popup: the same
    report the popup previews, on a narrow white sheet sized for sharing on WhatsApp. Rendered
    off-screen inside an iframe and captured with html2canvas (see pith-inventory.blade.php), so
    it carries its own @font-face with absolute URLs — the iframe never loads the page's
    stylesheets, whose oklch() colours html2canvas cannot parse.

    The report renders in its compact form (no footer, narrower Location column), and the
    Watered figure and column are dropped when nothing was watered in the period.

    Expects:
    - $report: shaped array from App\Services\Pulse\PithInventoryExportService::buildReport()
    - $teamName: string
    - $showWatering: bool (optional, default false)
--}}
@php
    $showWatering = ($showWatering ?? false) && round((float) ($report['grand_total']['watered_litres'] ?? 0)) != 0;
@endphp
<div class="pith-export-image" style="width: 720px; padding: 24px; background: #ffffff;">
    <style>
        @font-face { font-family: 'Figtree'; font-weight: 400 800; src: url('{{ asset('fonts/figtree/figtree-latin.woff2') }}') format('woff2'); }
        body { margin: 0; }
    </style>
    @include('pulse::livewire.pith.exports.inventory-report', ['report' => $report, 'teamName' => $teamName, 'showWatering' => $showWatering, 'compact' => true])
</div>
