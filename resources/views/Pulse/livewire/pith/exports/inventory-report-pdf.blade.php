<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $teamName }} — Pith Inventory Report</title>
    {{--
        Belt-and-suspenders alongside the ->setPaper('a4', 'portrait') call in
        PithInventoryExportController/ProductionSummaryService: an explicit
        @page rule is dompdf's own recommended way to pin page size, and takes
        precedence over the PHP-side setting if the two ever disagree.
    --}}
    <style>
        @page {
            size: A4 portrait;
        }
    </style>
</head>
<body>
    @include('pulse::livewire.pith.exports.inventory-report', ['report' => $report, 'teamName' => $teamName, 'showWatering' => $showWatering ?? false])

    {{--
        A4 portrait is ~595pt wide before margins, so the shared report is pinned to a width
        dompdf lays out reliably and its type is stepped down a touch; location names already
        wrap inside the fixed-layout table.

        (A pinned position:fixed footer was tried here and reverted: dompdf doesn't reliably
        reserve the @page bottom margin against its own table pagination, so the last row on a
        page could render on top of the footer text.)
    --}}
    <style>
        .pith-export-report { width: 680px; }
        .pith-export-report .pir-table { font-size: 12px; }
        .pith-export-report .pir-table th { font-size: 10px; padding: 8px 8px; }
        .pith-export-report .pir-table td { padding: 6px 8px; }
        .pith-export-report .pir-table td:first-child { padding-left: 18px; }
        .pith-export-report .pir-value { font-size: 16px; }
    </style>
</body>
</html>
