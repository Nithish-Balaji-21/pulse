{{--
    Standalone card-style layout used for "Export as Image" button across all Inventory Reports.
    Designed for high resolution screenshot generation (html2canvas / WhatsApp sharing).
--}}
@php
    $startDate = \Illuminate\Support\Carbon::parse($report['start_date']);
    $endDate = \Illuminate\Support\Carbon::parse($report['end_date']);
    $isSingleDay = $startDate->isSameDay($endDate);
    $dateLabel = $isSingleDay
        ? $startDate->format('l, d F Y')
        : $startDate->format('d M Y').' – '.$endDate->format('d M Y');
    $categoryLabel = $report['category'] ?? 'Inventory';
    $moduleNameMap = [
        'pith' => 'Raw Pith',
        'husk' => 'Husk',
        'bales' => 'Fibre Bales',
        'blocks' => 'Pith Blocks',
    ];
    $submoduleName = $moduleNameMap[$report['module'] ?? 'pith'] ?? 'Raw Pith';
    $reportTitle = "{$teamName} — {$submoduleName} {$categoryLabel} Report";
    $inwardLabel = $report['inward_label'] ?? 'Inward';
    $outwardLabel = $report['outward_label'] ?? 'Outward';
    $firstColHeader = ($report['module'] ?? 'pith') === 'pith' ? 'Location' : 'Day';
    $hasCorrection = in_array($report['module'] ?? '', ['husk', 'bales', 'blocks']);
    $fmt = fn ($value) => abs(round((float) $value)) < 1
        ? '–'
        : \App\Services\NumberFormatterService::formatToIndianFormat(round((float) $value), false, 0);
@endphp
<div class="pith-export-image">
    <style>
        .pith-export-image {
            width: 720px;
            font-family: Helvetica, Arial, sans-serif;
            background: #faf6ef;
        }
        .pith-export-image .header {
            background: #332a23;
            padding: 26px 28px 22px 28px;
        }
        .pith-export-image .kicker {
            margin: 0 0 8px 0;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #c8ad84;
        }
        .pith-export-image .title {
            margin: 0;
            font-size: 25px;
            font-weight: bold;
            color: #ffffff;
        }
        .pith-export-image .date-label {
            margin: 8px 0 0 0;
            font-size: 15px;
            color: #ddd3c3;
        }
        .pith-export-image .generated-label {
            margin: 4px 0 0 0;
            font-size: 11px;
            color: #a8998a;
        }
        .pith-export-image .divider {
            height: 5px;
            background: #c9982f;
        }
        .pith-export-image .body {
            padding: 24px 28px 20px 28px;
        }
        .pith-export-image .overview {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
            margin: 0 0 24px -8px;
        }
        .pith-export-image .overview td {
            width: {{ $hasCorrection ? '20%' : '25%' }};
            background: #ffffff;
            border: 1px solid #ece3d5;
            border-radius: 8px;
            padding: 10px 12px;
        }
        .pith-export-image .overview-label {
            display: block;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #8a8478;
            margin: 0 0 4px 0;
        }
        .pith-export-image .overview-value {
            display: block;
            font-size: 17px;
            font-weight: bold;
        }
        .pith-export-image .overview-value.opening { color: #2b2420; }
        .pith-export-image .overview-value.inward { color: #c0392b; }
        .pith-export-image .overview-value.correction { color: #d97706; }
        .pith-export-image .overview-value.outward { color: #2f6690; }
        .pith-export-image .overview-value.closing { color: #2e7d47; }
        .pith-export-image .section-title {
            margin: 0 0 4px 0;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #9a3324;
        }
        .pith-export-image .section-underline {
            width: 44px;
            height: 3px;
            background: #c9982f;
            margin: 0 0 14px 0;
        }
        .pith-export-image table.locations {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: 12px;
        }
        .pith-export-image table.locations th:nth-child(1),
        .pith-export-image table.locations td:nth-child(1) { width: {{ $hasCorrection ? '25%' : '26%' }}; }
        .pith-export-image table.locations th:nth-child(2),
        .pith-export-image table.locations td:nth-child(2) { width: {{ $hasCorrection ? '15%' : '19%' }}; }
        .pith-export-image table.locations th:nth-child(3),
        .pith-export-image table.locations td:nth-child(3) { width: {{ $hasCorrection ? '15%' : '18%' }}; }
        @if ($hasCorrection)
        .pith-export-image table.locations th:nth-child(4),
        .pith-export-image table.locations td:nth-child(4) { width: 15%; }
        .pith-export-image table.locations th:nth-child(5),
        .pith-export-image table.locations td:nth-child(5) { width: 15%; }
        .pith-export-image table.locations th:nth-child(6),
        .pith-export-image table.locations td:nth-child(6) { width: 15%; }
        @else
        .pith-export-image table.locations th:nth-child(4),
        .pith-export-image table.locations td:nth-child(4) { width: 18%; }
        .pith-export-image table.locations th:nth-child(5),
        .pith-export-image table.locations td:nth-child(5) { width: 19%; }
        @endif
        .pith-export-image table.locations th {
            text-align: right;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #9a948a;
            padding: 10px 10px;
            vertical-align: middle;
            border-bottom: 1px solid #e5ddd0;
        }
        .pith-export-image table.locations th:first-child {
            text-align: left;
            padding-left: 14px;
        }
        .pith-export-image table.locations td {
            padding: 8px 10px;
            vertical-align: middle;
            text-align: right;
            border-bottom: 1px solid #efe8db;
            color: #2b2420;
        }
        .pith-export-image table.locations td:first-child {
            text-align: left;
            padding-left: 14px;
        }
        .pith-export-image tr.group-row td {
            background: #f4ece0;
            border-bottom: none;
            font-weight: bold;
            padding: 8px 10px;
            vertical-align: middle;
        }
        .pith-export-image tr.group-row td:first-child {
            border-left: 4px solid #9a3324;
            padding-left: 10px;
        }
        .pith-export-image .group-count {
            font-size: 11px;
            font-weight: normal;
            color: #6b5c4d;
            margin-left: 4px;
        }
        .pith-export-image tr.location-row td:first-child {
            padding-left: 24px;
        }
        .pith-export-image tr.location-row.quiet td {
            color: #948c80;
        }
        .pith-export-image tr.location-row.moved td {
            font-weight: bold;
            color: #2b2420;
        }
        .pith-export-image tr.location-row.moved td.inward-cell { color: #c0392b; }
        .pith-export-image tr.location-row.moved td.correction-cell { color: #d97706; }
        .pith-export-image tr.location-row.moved td.outward-cell { color: #2f6690; }
        .pith-export-image tr.location-row.moved td.closing-cell { color: #2e7d47; }
        .pith-export-image .empty {
            padding: 20px 0;
            text-align: center;
            color: #948c80;
        }
    </style>

    <div class="header">
        <p class="kicker">CoiioT Pulse</p>
        <h1 class="title">{{ $reportTitle }}</h1>
        <p class="date-label">{{ $dateLabel }}</p>
        <p class="generated-label">Generated {{ \Illuminate\Support\Carbon::parse($report['generated_at'])->format('d M Y, h:i A') }}</p>
    </div>
    <div class="divider"></div>

    <div class="body">
        <table class="overview">
            <tr>
                <td>
                    <span class="overview-label">Opening</span>
                    <span class="overview-value opening">{{ $fmt($report['grand_total']['opening_stock']) }}</span>
                </td>
                <td>
                    <span class="overview-label">{{ $inwardLabel }}</span>
                    <span class="overview-value inward">{{ $fmt($report['grand_total']['inward']) }}</span>
                </td>
                @if ($hasCorrection)
                <td>
                    <span class="overview-label">Correction</span>
                    <span class="overview-value correction">{{ ($report['grand_total']['correction'] ?? 0) > 0 ? '+' : '' }}{{ $fmt($report['grand_total']['correction'] ?? 0) }}</span>
                </td>
                @endif
                <td>
                    <span class="overview-label">{{ $outwardLabel }}</span>
                    <span class="overview-value outward">{{ $fmt($report['grand_total']['outward']) }}</span>
                </td>
                <td>
                    <span class="overview-label">In Stock</span>
                    <span class="overview-value closing">{{ $fmt($report['grand_total']['closing_stock']) }}</span>
                </td>
            </tr>
        </table>

        <p class="section-title">Ledger Details</p>
        <div class="section-underline"></div>

        <table class="locations">
            <thead>
                <tr>
                    <th>{{ $firstColHeader }}</th>
                    <th>Opening</th>
                    <th>{{ $inwardLabel }}</th>
                    @if ($hasCorrection)
                    <th>Correction</th>
                    @endif
                    <th>{{ $outwardLabel }}</th>
                    <th>In Stock</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['groups'] as $group)
                    @if (($report['module'] ?? 'pith') === 'pith')
                        <tr class="group-row">
                            <td>{{ $group['name'] }} <span class="group-count">({{ $group['location_count'] }})</span></td>
                            <td>{{ $fmt($group['subtotal']['opening_stock']) }}</td>
                            <td>{{ $fmt($group['subtotal']['inward']) }}</td>
                            <td>{{ $fmt($group['subtotal']['outward']) }}</td>
                            <td>{{ $fmt($group['subtotal']['closing_stock']) }}</td>
                        </tr>
                    @endif
                    @foreach ($group['locations'] as $location)
                        <tr class="location-row {{ !empty($location['has_movement']) ? 'moved' : 'quiet' }}">
                            <td>
                                {{ $location['name'] }}
                            </td>
                            <td>{{ $fmt($location['opening_stock']) }}</td>
                            <td class="inward-cell">{{ $fmt($location['inward']) }}</td>
                            @if ($hasCorrection)
                            <td class="correction-cell">
                                @if (!empty($location['correction']) && $location['correction'] != 0)
                                    {{ ($location['correction'] > 0 ? '+' : '') . $fmt($location['correction']) }}
                                @else
                                    –
                                @endif
                            </td>
                            @endif
                            <td class="outward-cell">{{ $fmt($location['outward']) }}</td>
                            <td class="closing-cell">{{ $fmt($location['closing_stock']) }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="{{ $hasCorrection ? 6 : 5 }}" class="empty">No inventory movement configured for this team.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
