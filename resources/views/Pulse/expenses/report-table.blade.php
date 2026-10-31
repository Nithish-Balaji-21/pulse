<div class="overflow-x-auto rounded-xl border border-gray-200">
<table class="w-full text-sm text-left" style="border-collapse:collapse;width:100%">
    <thead style="background:#f3f4f6"><tr>@foreach(['Category','Staff / Item','Task / Description','Paid by','Billed to','Amount'] as $label)<th class="p-3" style="padding:12px;{{ $label === 'Amount' ? 'text-align:right' : '' }}">{{ $label }}</th>@endforeach</tr></thead>
    <tbody>@forelse($rows as $row)
        <tr style="border-top:1px solid #e5e7eb"><td class="p-3 capitalize" style="padding:12px">{{ ucfirst($row['category']) }}</td>
        <td class="p-3 font-semibold" style="padding:12px">{{ $row['name'] }}</td>
        <td class="p-3" style="padding:12px">{{ $row['description'] }}<p class="text-gray-500">{{ $row['detail'] }}@if($row['date']) {{ \Carbon\Carbon::parse($row['date'])->format('d M Y') }}@endif</p></td>
        <td class="p-3" style="padding:12px">{{ $row['paid_by'] }}</td><td class="p-3" style="padding:12px">{{ $row['billed_to'] }}</td>
        <td class="p-3 text-right font-semibold whitespace-nowrap" style="padding:12px;text-align:right">{{ \App\Services\NumberFormatterService::formatToIndianCurrency($row['amount'], 0) }}</td></tr>
    @empty<tr><td colspan="6" style="padding:24px">No expenses recorded for this category and period.</td></tr>@endforelse</tbody>
    <tfoot style="background:#f3f4f6;border-top:1px solid #d1d5db;font-weight:bold"><tr><td colspan="5" style="padding:12px">{{ $category === 'all' ? 'Total' : 'Filtered total' }}</td><td style="padding:12px;text-align:right">{{ \App\Services\NumberFormatterService::formatToIndianCurrency($filteredTotal, 0) }}</td></tr></tfoot>
</table></div>
