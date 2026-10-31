@php
    $categoryLabel = $report['category'] ?? 'Inventory';
    $moduleNameMap = [
        'pith' => 'Raw Pith',
        'husk' => 'Husk',
        'bales' => 'Fibre Bales',
        'blocks' => 'Pith Blocks',
    ];
    $submoduleName = $moduleNameMap[$report['module'] ?? 'pith'] ?? 'Raw Pith';
    $moduleTitle = "{$categoryLabel} — {$submoduleName}";
    $unitLabel = $report['unit_label'] ?? 'CFT';
    $inwardLabel = $report['inward_label'] ?? 'Inward';
    $outwardLabel = $report['outward_label'] ?? 'Outward';
    $firstColHeader = ($report['module'] ?? 'pith') === 'pith' ? 'Location' : 'Day';
    $hasCorrection = in_array($report['module'] ?? '', ['husk', 'bales', 'blocks']);
@endphp
<div class="pith-export-report">
    <style>
        .pith-export-report {
            font-family: Helvetica, Arial, sans-serif;
            color: #1f2937;
            background: #ffffff;
            padding: 20px;
            width: 800px;
        }
        .pith-export-report h1 {
            font-size: 18px;
            font-weight: bold;
            margin: 0 0 4px 0;
        }
        .pith-export-report .meta {
            font-size: 11px;
            color: #6b7280;
            margin: 0 0 16px 0;
        }
        .pith-export-report .overview {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 16px 0;
        }
        .pith-export-report .overview td {
            border: 1px solid #d1d5db;
            padding: 8px 10px;
            text-align: left;
            width: {{ $hasCorrection ? '20%' : '25%' }};
        }
        .pith-export-report .overview .overview-label {
            display: block;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #6b7280;
            margin: 0 0 3px 0;
        }
        .pith-export-report .overview .overview-value {
            display: block;
            font-size: 15px;
            font-weight: bold;
            color: #1f2937;
        }
        .pith-export-report table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .pith-export-report th,
        .pith-export-report td {
            border: 1px solid #d1d5db;
            padding: 5px 8px;
            text-align: right;
            overflow-wrap: break-word;
        }
        .pith-export-report th:first-child,
        .pith-export-report td:first-child {
            text-align: left;
        }
        .pith-export-report table.locations {
            table-layout: fixed;
        }
        .pith-export-report table.locations th:nth-child(1),
        .pith-export-report table.locations td:nth-child(1) { width: {{ $hasCorrection ? '25%' : '26%' }}; }
        .pith-export-report table.locations th:nth-child(2),
        .pith-export-report table.locations td:nth-child(2) { width: {{ $hasCorrection ? '15%' : '19%' }}; }
        .pith-export-report table.locations th:nth-child(3),
        .pith-export-report table.locations td:nth-child(3) { width: {{ $hasCorrection ? '15%' : '18%' }}; }
        @if ($hasCorrection)
        .pith-export-report table.locations th:nth-child(4),
        .pith-export-report table.locations td:nth-child(4) { width: 15%; }
        .pith-export-report table.locations th:nth-child(5),
        .pith-export-report table.locations td:nth-child(5) { width: 15%; }
        .pith-export-report table.locations th:nth-child(6),
        .pith-export-report table.locations td:nth-child(6) { width: 15%; }
        @else
        .pith-export-report table.locations th:nth-child(4),
        .pith-export-report table.locations td:nth-child(4) { width: 18%; }
        .pith-export-report table.locations th:nth-child(5),
        .pith-export-report table.locations td:nth-child(5) { width: 19%; }
        @endif
        .pith-export-report thead th {
            background: #f3f4f6;
            font-weight: bold;
        }
        .pith-export-report .group-row td {
            background: #eef2ff;
            font-weight: bold;
            text-align: left;
        }
        .pith-export-report .subtotal-row td {
            background: #f9fafb;
            font-weight: bold;
        }
        .pith-export-report .moved-row td {
            font-weight: bold;
            background: #fffbeb;
        }
        .pith-export-report .grand-total-row td {
            background: #e5e7eb;
            font-weight: bold;
            border-top: 2px solid #1f2937;
        }
        .pith-export-report .empty {
            padding: 16px 8px;
            text-align: center;
            color: #6b7280;
        }
    </style>

    <h1>{{ $teamName }} — {{ $submoduleName }} {{ $categoryLabel }} Report</h1>
    <p class="meta">
        {{ \Illuminate\Support\Carbon::parse($report['start_date'])->format('d M Y') }}
        to
        {{ \Illuminate\Support\Carbon::parse($report['end_date'])->format('d M Y') }}
        &nbsp;·&nbsp; Generated {{ \Illuminate\Support\Carbon::parse($report['generated_at'])->format('d M Y H:i') }}
    </p>

    <table class="overview">
        <tr>
            <td>
                <span class="overview-label">Opening ({{ $unitLabel }})</span>
                <span class="overview-value">{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['opening_stock'], false, 0) }}</span>
            </td>
            <td>
                <span class="overview-label">{{ $inwardLabel }} ({{ $unitLabel }})</span>
                <span class="overview-value">{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['inward'], false, 0) }}</span>
            </td>
            @if ($hasCorrection)
            <td>
                <span class="overview-label">Correction ({{ $unitLabel }})</span>
                <span class="overview-value">{{ ($report['grand_total']['correction'] ?? 0) > 0 ? '+' : '' }}{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['correction'] ?? 0, false, 0) }}</span>
            </td>
            @endif
            <td>
                <span class="overview-label">{{ $outwardLabel }} ({{ $unitLabel }})</span>
                <span class="overview-value">{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['outward'], false, 0) }}</span>
            </td>
            <td>
                <span class="overview-label">Closing ({{ $unitLabel }})</span>
                <span class="overview-value">{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['closing_stock'], false, 0) }}</span>
            </td>
        </tr>
    </table>

    <table class="locations">
        <thead>
            <tr>
                <th>{{ $firstColHeader }}</th>
                <th>Opening ({{ $unitLabel }})</th>
                <th>{{ $inwardLabel }} ({{ $unitLabel }})</th>
                @if ($hasCorrection)
                <th>Correction ({{ $unitLabel }})</th>
                @endif
                <th>{{ $outwardLabel }} ({{ $unitLabel }})</th>
                <th>Closing ({{ $unitLabel }})</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['groups'] as $group)
                @if (($report['module'] ?? 'pith') === 'pith')
                    <tr class="group-row">
                        <td colspan="{{ $hasCorrection ? 6 : 5 }}">{{ $group['name'] }} ({{ $group['location_count'] }})</td>
                    </tr>
                @endif
                @foreach ($group['locations'] as $location)
                    <tr class="{{ !empty($location['has_movement']) ? 'moved-row' : '' }}">
                        <td>
                            {{ $location['name'] }}
                        </td>
                        <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($location['opening_stock'], false, 0) }}</td>
                        <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($location['inward'], false, 0) }}</td>
                        @if ($hasCorrection)
                        <td>
                            @if (!empty($location['correction']) && $location['correction'] != 0)
                                {{ ($location['correction'] > 0 ? '+' : '') . \App\Services\NumberFormatterService::formatToIndianFormat($location['correction'], false, 0) }}
                            @else
                                –
                            @endif
                        </td>
                        @endif
                        <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($location['outward'], false, 0) }}</td>
                        <td>
                            {{ \App\Services\NumberFormatterService::formatToIndianFormat($location['closing_stock'], false, 0) }}
                        </td>
                    </tr>
                @endforeach
                @if (count($group['locations']) > 1 && ($report['module'] ?? 'pith') === 'pith')
                <tr class="subtotal-row">
                    <td>{{ $group['name'] }} subtotal</td>
                    <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($group['subtotal']['opening_stock'], false, 0) }}</td>
                    <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($group['subtotal']['inward'], false, 0) }}</td>
                    <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($group['subtotal']['outward'], false, 0) }}</td>
                    <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($group['subtotal']['closing_stock'], false, 0) }}</td>
                </tr>
                @endif
            @empty
                <tr>
                    <td colspan="{{ $hasCorrection ? 6 : 5 }}" class="empty">No inventory movement recorded in this period.</td>
                </tr>
            @endforelse
        </tbody>
        @if (count($report['groups']) > 0)
            <tfoot>
                <tr class="grand-total-row">
                    <td>Grand total</td>
                    <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['opening_stock'], false, 0) }}</td>
                    <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['inward'], false, 0) }}</td>
                    @if ($hasCorrection)
                    <td>{{ ($report['grand_total']['correction'] ?? 0) > 0 ? '+' : '' }}{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['correction'] ?? 0, false, 0) }}</td>
                    @endif
                    <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['outward'], false, 0) }}</td>
                    <td>{{ \App\Services\NumberFormatterService::formatToIndianFormat($report['grand_total']['closing_stock'], false, 0) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
