{{--
    Shared report markup for the Pith Inventory date-range export: the on-screen preview in the
    Filter & Export popup, the dompdf PDF (via inventory-report-pdf.blade.php) and the emailed
    summary attachment. Tables and plain, self-contained CSS only — dompdf has no flex or grid
    and the page's Tailwind classes aren't available to it.

    Expects:
    - $report: shaped array from App\Services\Pulse\PithInventoryExportService::buildReport()
    - $teamName: string
    - $showWatering: bool (optional, default false) — adds the Watered (L) column and summary figure
    - $compact: bool (optional, default false) — the WhatsApp-sized image: narrower Location
      column, tighter cells and no explanatory footer

    Each row reads Opening + Inward − Outward − Settled = Closing. "Settled" is what the period's
    batch closures took off the books: positive when stock was still on the books at closure,
    negative when outward had overdrawn the batch. Each closure is noted under the location.
    Rows with any inward, outward, settled or (when shown) watered amount are tinted so the
    locations that moved stand out from the ones that only carried stock forward.
--}}
@php
    $showWatering = $showWatering ?? false;
    $compact = $compact ?? false;
    $num = function ($value) {
        $value = round((float) $value);

        return \App\Services\NumberFormatterService::formatToIndianFormat($value == 0 ? 0 : $value, false, 0);
    };
    $start = \Illuminate\Support\Carbon::parse($report['start_date']);
    $end = \Illuminate\Support\Carbon::parse($report['end_date']);
    $rangeLabel = $start->isSameDay($end) ? $start->format('j M Y') : $start->format('j M Y').' to '.$end->format('j M Y');
    $total = $report['grand_total'];
    $columns = $showWatering ? 7 : 6;
    $closureNote = function (array $closure) use ($num) {
        $settled = round((float) $closure['settled']);
        $outcome = match (true) {
            $settled > 0 => $num($settled).' cleared',
            $settled < 0 => $num(abs($settled)).' over-drawn',
            default => 'nothing left',
        };
        $ratio = $closure['outward_ratio'] !== null ? ' ('.round($closure['outward_ratio']).'%)' : '';

        return 'Closed '.\Illuminate\Support\Carbon::parse($closure['date'])->format('j M')
            .' · In '.$num($closure['inward']).' · Out '.$num($closure['outward']).$ratio.' · '.$outcome;
    };
    $hasMovement = fn (array $location) => round((float) $location['inward']) != 0
        || round((float) $location['outward']) != 0
        || round((float) $location['write_off_in_range']) != 0
        || ($showWatering && round((float) ($location['watered_litres'] ?? 0)) != 0);
@endphp
<div class="pith-export-report{{ $compact ? ' pir-compact' : '' }}">
    <style>
        .pith-export-report { font-family: Figtree, Helvetica, Arial, sans-serif; color: #1B1B1F; background: #ffffff; width: 100%; }
        .pith-export-report .pir-title { font-size: 22px; font-weight: bold; margin: 0 0 4px 0; }
        .pith-export-report .pir-meta { font-size: 13px; color: #6B6A72; margin: 0 0 20px 0; }
        .pith-export-report table { width: 100%; border-collapse: collapse; }
        .pith-export-report .pir-summary { border-top: 1px solid #EEEDF2; border-bottom: 1px solid #EEEDF2; margin: 0 0 20px 0; }
        .pith-export-report .pir-summary td { padding: 14px 12px; vertical-align: top; border-left: 1px solid #EEEDF2; }
        .pith-export-report .pir-summary td:first-child { padding-left: 0; border-left: none; }
        .pith-export-report .pir-label { display: block; font-size: 12px; font-weight: bold; color: #55545C; margin: 0 0 2px 0; }
        .pith-export-report .pir-value { display: block; font-size: 19px; font-weight: bold; }
        .pith-export-report .pir-table { table-layout: fixed; font-size: 14px; }
        .pith-export-report .pir-table th { font-size: 11px; font-weight: bold; letter-spacing: .07em; text-transform: uppercase; color: #55545C; padding: 10px 12px; border-bottom: 1.5px solid #1B1B1F; text-align: right; }
        .pith-export-report .pir-table th:first-child { text-align: left; width: 30%; }
        .pith-export-report .pir-table td { padding: 8px 12px; text-align: right; border-bottom: 1px solid #F1F0F4; }
        .pith-export-report .pir-table td:first-child { text-align: left; padding-left: 24px; }
        .pith-export-report .pir-group td { border-bottom: none; padding: 14px 12px 6px; font-size: 13px; font-weight: bold; color: #55545C; text-align: left; }
        .pith-export-report .pir-group td:first-child { padding-left: 12px; }
        .pith-export-report .pir-group span { font-weight: normal; color: #8E8D96; }
        .pith-export-report .pir-closing { font-weight: bold; }
        .pith-export-report .pir-zero { color: #B9B8C2; }
        .pith-export-report .pir-water { color: #173B54; }
        .pith-export-report .pir-active td { background: #FFF7E6; }
        .pith-export-report .pir-subtotal td { background: #F7F7F9; font-weight: bold; border-bottom: none; }
        .pith-export-report .pir-total td { border-top: 1.5px solid #1B1B1F; border-bottom: none; font-size: 15px; font-weight: bold; padding-top: 12px; padding-bottom: 12px; }
        .pith-export-report .pir-total td:first-child { padding-left: 12px; }
        .pith-export-report .pir-spacer td { border: none; height: 10px; padding: 0; }
        .pith-export-report .pir-note { display: block; font-size: 11px; font-weight: normal; color: #9A5A12; }
        .pith-export-report .pir-empty { padding: 28px; text-align: center !important; font-size: 14px; color: #6B6A72; }
        .pith-export-report .pir-foot { font-size: 12px; color: #8E8D96; margin: 16px 0 0 0; }
        .pith-export-report.pir-compact .pir-meta { margin-bottom: 14px; }
        .pith-export-report.pir-compact .pir-summary { margin-bottom: 14px; }
        .pith-export-report.pir-compact .pir-summary td { padding: 10px 10px; }
        .pith-export-report.pir-compact .pir-value { font-size: 17px; }
        .pith-export-report.pir-compact .pir-table th { padding: 8px 8px; }
        .pith-export-report.pir-compact .pir-table th:first-child { width: 24%; }
        .pith-export-report.pir-compact .pir-table td { padding: 6px 8px; }
        .pith-export-report.pir-compact .pir-table td:first-child { padding-left: 14px; }
        .pith-export-report.pir-compact .pir-group td { padding: 10px 8px 4px; }
        .pith-export-report.pir-compact .pir-group td:first-child,
        .pith-export-report.pir-compact .pir-total td:first-child { padding-left: 8px; }
    </style>

    <div class="pir-title">{{ $teamName }} · Pith Inventory Report</div>
    <div class="pir-meta">{{ $rangeLabel }} · Generated {{ \Illuminate\Support\Carbon::parse($report['generated_at'])->format('j M Y, H:i') }}</div>

    <table class="pir-summary">
        <tr>
            <td><span class="pir-label">Opening</span><span class="pir-value">{{ $num($total['opening_stock']) }}</span></td>
            <td><span class="pir-label" style="color: #8B2323">Inward</span><span class="pir-value">{{ $num($total['inward']) }}</span></td>
            <td><span class="pir-label" style="color: #9A5A12">Outward</span><span class="pir-value">{{ $num($total['outward']) }}</span></td>
            <td><span class="pir-label" style="color: #6B6A72">Settled at close</span><span class="pir-value">{{ $num($total['write_off_in_range']) }}</span></td>
            <td><span class="pir-label" style="color: #2E7359">Closing</span><span class="pir-value">{{ $num($total['closing_stock']) }}</span></td>
            @if ($showWatering)
                <td><span class="pir-label" style="color: #2F78A8">Watered</span><span class="pir-value pir-water">{{ $num($total['watered_litres'] ?? 0) }} L</span></td>
            @endif
        </tr>
    </table>

    <table class="pir-table">
        <thead>
            <tr>
                <th>Location</th>
                <th>Opening</th>
                <th style="color: #8B2323">Inward</th>
                <th style="color: #9A5A12">Outward</th>
                <th style="color: #6B6A72">Settled</th>
                <th style="color: #2E7359">Closing</th>
                @if ($showWatering)
                    <th style="color: #2F78A8">Watered (L)</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($report['groups'] as $group)
                <tr class="pir-group">
                    <td colspan="{{ $columns }}">{{ $group['name'] }} <span>· {{ $group['location_count'] }}</span></td>
                </tr>
                @foreach ($group['locations'] as $location)
                    <tr class="{{ $hasMovement($location) ? 'pir-active' : '' }}">
                        <td>
                            {{ $location['name'] }}
                            @foreach ($location['closures'] ?? [] as $closure)
                                <span class="pir-note">{{ $closureNote($closure) }}</span>
                            @endforeach
                        </td>
                        <td>{{ $num($location['opening_stock']) }}</td>
                        <td class="{{ round($location['inward']) == 0 ? 'pir-zero' : '' }}">{{ $num($location['inward']) }}</td>
                        <td class="{{ round($location['outward']) == 0 ? 'pir-zero' : '' }}">{{ $num($location['outward']) }}</td>
                        <td class="{{ round($location['write_off_in_range']) == 0 ? 'pir-zero' : '' }}">{{ $num($location['write_off_in_range']) }}</td>
                        <td class="pir-closing">{{ $num($location['closing_stock']) }}</td>
                        @if ($showWatering)
                            <td class="{{ ($location['watered_litres'] ?? 0) == 0 ? 'pir-zero' : 'pir-water' }}">{{ $num($location['watered_litres'] ?? 0) }}</td>
                        @endif
                    </tr>
                @endforeach
                {{-- A one-location group's subtotal just restates the row above it, so skip it. --}}
                @if (count($group['locations']) > 1)
                    <tr class="pir-subtotal">
                        <td>Subtotal</td>
                        <td>{{ $num($group['subtotal']['opening_stock']) }}</td>
                        <td>{{ $num($group['subtotal']['inward']) }}</td>
                        <td>{{ $num($group['subtotal']['outward']) }}</td>
                        <td>{{ $num($group['subtotal']['write_off_in_range']) }}</td>
                        <td>{{ $num($group['subtotal']['closing_stock']) }}</td>
                        @if ($showWatering)
                            <td class="pir-water">{{ $num($group['subtotal']['watered_litres'] ?? 0) }}</td>
                        @endif
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="{{ $columns }}" class="pir-empty">No stock or movement in this period.</td>
                </tr>
            @endforelse
            @if (count($report['groups']) > 0)
                <tr class="pir-spacer"><td colspan="{{ $columns }}"></td></tr>
                <tr class="pir-total">
                    <td>All locations</td>
                    <td>{{ $num($total['opening_stock']) }}</td>
                    <td>{{ $num($total['inward']) }}</td>
                    <td>{{ $num($total['outward']) }}</td>
                    <td>{{ $num($total['write_off_in_range']) }}</td>
                    <td>{{ $num($total['closing_stock']) }}</td>
                    @if ($showWatering)
                        <td class="pir-water">{{ $num($total['watered_litres'] ?? 0) }}</td>
                    @endif
                </tr>
            @endif
        </tbody>
    </table>
    @unless ($compact)
        <div class="pir-foot">Stock in cft{{ $showWatering ? ' · water in litres' : '' }} · Opening + Inward − Outward − Settled = Closing. Highlighted rows had movement in the period. Settled is what batch closures took off the books: positive for stock cleared, negative for stock over-drawn.</div>
    @endunless
</div>
