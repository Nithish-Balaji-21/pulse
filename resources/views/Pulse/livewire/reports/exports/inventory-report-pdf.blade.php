<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $teamName }} — {{ $report['module_title'] ?? 'Inventory Report' }}</title>
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
    @include('Pulse.livewire.reports.exports.inventory-report', ['report' => $report, 'teamName' => $teamName])

    {{--
        The shared table above is fixed at 800px so it also fits comfortably
        inside the on-screen Filter & Export modal; A4 portrait is narrower
        than that (~595pt/~790px full page width before margins), so shrink
        it here and trim the location column a touch — locations already
        wrap (overflow-wrap: break-word in the shared stylesheet), and a
        narrower page just means they wrap sooner.

        (A pinned position:fixed footer for the legend line was tried here and
        reverted: dompdf doesn't reliably reserve the @page bottom margin
        against its own table pagination, so the last row on a page could
        render on top of the footer text. Letting the legend flow normally
        after the table — same as before — occasionally leaves it alone on a
        near-blank trailing page, but that's a much smaller cost than
        unreadable overlapping text.)
    --}}
    <style>
        .pith-export-report {
            width: 680px;
        }
        .pith-export-report table.locations th:nth-child(1),
        .pith-export-report table.locations td:nth-child(1) {
            width: 22%;
        }
    </style>
</body>
</html>
