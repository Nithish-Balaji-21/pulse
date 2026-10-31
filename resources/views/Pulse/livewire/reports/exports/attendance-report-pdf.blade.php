<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Staff Attendance & Salary Report</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #333; margin: 0; padding: 15px; }
        .header { margin-bottom: 15px; border-bottom: 2px solid #7a221e; padding-bottom: 8px; }
        .title { font-size: 16px; font-weight: bold; color: #7a221e; margin-bottom: 3px; }
        .subtitle { font-size: 9px; color: #666; }
        .filter-badge { font-size: 9px; background: #f8f9fa; border: 1px solid #dee2e6; padding: 5px 8px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        .kpi-table { width: 100%; margin-bottom: 15px; border-collapse: collapse; }
        .kpi-card { background: #f8f9fa; border: 1px solid #e9ecef; padding: 8px; text-align: center; border-radius: 4px; }
        .kpi-val { font-size: 13px; font-weight: bold; color: #111; }
        .kpi-lbl { font-size: 7.5px; color: #666; font-weight: bold; text-transform: uppercase; margin-top: 2px; }
        .section-title { font-size: 11px; font-weight: bold; color: #7a221e; margin-top: 15px; margin-bottom: 5px; text-transform: uppercase; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        .data-table th { background: #f1f3f5; color: #495057; font-size: 8.5px; font-weight: bold; text-transform: uppercase; padding: 6px; border-bottom: 1px solid #dee2e6; }
        .data-table td { padding: 6px; border-bottom: 1px solid #f1f3f5; font-size: 9px; }
        .total-row { font-weight: bold; background: #f8f9fa; border-top: 2px solid #dee2e6; }
        .badge-present { color: #2b8a3e; font-weight: bold; }
        .badge-absent { color: #dc2626; font-weight: bold; }
        .footer { margin-top: 25px; font-size: 8px; color: #999; text-align: center; border-top: 1px solid #eee; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Staff Attendance & Salary Report</div>
        <div class="subtitle">{{ $teamName }} · {{ \Carbon\Carbon::parse($report['start_date'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($report['end_date'])->format('d M Y') }}</div>
    </div>

    <div class="filter-badge">
        Filter Applied: {{ $report['applied_filter_label'] ?? '' }}
    </div>

    <div class="section-title">Worker-wise Summary</div>
    <table class="data-table">
        <thead>
            <tr>
                <th align="left">WORKER NAME</th>
                <th align="center">PRESENT</th>
                <th align="center">ABSENT</th>
                <th align="center">AVG. HOURS/DAY</th>
                <th align="center">HOURS WORKED</th>
                <th align="right">TOTAL SALARY (₹)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['worker_summary'] ?? [] as $row)
                <tr>
                    <td align="left" style="font-weight: bold;">{{ $row['staff_name'] }}</td>
                    <td align="center" class="badge-present">{{ $row['present_days'] }}</td>
                    <td align="center" class="badge-absent">{{ $row['absent_days'] }}</td>
                    <td align="center">{{ $row['avg_hours_per_day_formatted'] }}</td>
                    <td align="center">{{ $row['total_duration_formatted'] }}</td>
                    <td align="right" style="font-weight: bold; color: #2b8a3e;">₹{{ number_format($row['total_salary'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" align="center" style="color: #999; padding: 10px;">No records found.</td>
                </tr>
            @endforelse

            @if (!empty($report['worker_summary']))
                <tr class="total-row">
                    <td align="left">Total ({{ count($report['worker_summary']) }} Workers)</td>
                    <td align="center" class="badge-present">{{ $report['summary']['total_present_days'] ?? 0 }}</td>
                    <td align="center" class="badge-absent">{{ $report['summary']['total_absent_days'] ?? 0 }}</td>
                    <td align="center">{{ $report['summary']['overall_avg_hours_per_day_formatted'] ?? '0h' }}</td>
                    <td align="center">{{ $report['summary']['total_hours_formatted'] ?? '0h' }}</td>
                    <td align="right" style="color: #2b8a3e;">₹{{ number_format($report['summary']['total_salary'] ?? 0, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Generated on {{ now()->format('d M Y, h:i A') }} · Official CoiioT Pulse Report Engine
    </div>
</body>
</html>
