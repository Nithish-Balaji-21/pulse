<x-app-layout>
    @php($expenseRoute = request()->routeIs('reports.expenses') ? 'reports.expenses' : 'expenses.index')
    <x-slot name="header"><h2 class="text-2xl font-semibold text-gray-900">{{ $expenseRoute === 'reports.expenses' ? 'Expense Report' : 'Expenses' }}</h2><p class="text-sm text-gray-500 mt-1">Alam Cocos</p></x-slot>
    <form method="GET" class="flex gap-3 items-end flex-wrap mb-5">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label class="text-sm text-gray-600">From<input type="date" name="from" value="{{ $from }}" required class="block rounded-lg border-gray-300"></label>
        <label class="text-sm text-gray-600">To<input type="date" name="to" value="{{ $to }}" required class="block rounded-lg border-gray-300"></label>
        <button class="rounded-lg bg-[#83211b] text-white px-4 py-2">Show expenses</button>
    </form>
    @if ($errors->any())<p class="text-red-700 mb-4">{{ $errors->first() }}</p>@endif
    @if ($report)
        @php($money = fn ($amount) => \App\Services\NumberFormatterService::formatToIndianCurrency((float) $amount, 2))
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
            @foreach (['staff' => 'Staff expense', 'other' => 'Other expense', 'total' => 'Total expenses'] as $key => $label)
                <div class="bg-white border rounded-xl p-5"><p class="text-sm uppercase tracking-wide text-gray-500">{{ $label }}</p><p class="text-2xl font-semibold text-gray-900 mt-2">{{ $money($report['totals'][$key]) }}</p>
                    <p class="text-sm text-gray-500 mt-1">{{ $key === 'staff' ? count($report['staff']['rows']).' staff' : ($key === 'other' ? 'Vehicles & materials' : 'For the selected period') }}</p>
                </div>
            @endforeach
        </div>
        <nav aria-label="Expense sections" class="flex flex-wrap gap-2 bg-white border rounded-t-xl px-4 pt-2">
            @foreach (['staff' => 'Staff Expense', 'other' => 'Other Expense'] as $key => $label)
                <a href="{{ route($expenseRoute, ['team' => $team->id, 'from' => $from, 'to' => $to, 'tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif class="px-4 py-3 border-b-2 text-sm font-semibold {{ $tab === $key ? 'border-[#83211b] text-[#83211b]' : 'border-transparent text-gray-500' }}">{{ $label }}</a>
            @endforeach
        </nav>
        <section class="bg-white border border-t-0 rounded-b-xl p-5">
        @if ($tab === 'staff')
            <h3 class="text-lg font-semibold">Staff Expense · Cost by Task</h3><p class="text-sm text-gray-500 mt-1 mb-4">Alam Cocos staff costs by hourly task and activity. Open-week figures may change.</p>
            @if (count($report['staff']['rows']) === 0)<p class="text-gray-500 py-6">No staff work recorded for this period.</p>
            @else
            <div class="overflow-x-auto"><table class="w-full text-sm text-left whitespace-nowrap">
                <thead class="bg-gray-50"><tr><th class="p-3">Staff</th>@foreach($report['staff']['columns'] as $column)<th class="p-3 text-right">{{ $column['label'] }}</th>@endforeach<th class="p-3 text-right">Total</th></tr></thead>
                <tbody>@foreach($report['staff']['rows'] as $row)
                    <tr class="border-t"><td class="p-3 font-semibold">{{ $row['name'] }}</td>
                    @foreach($report['staff']['columns'] as $column)
                        @php($cell = $row['cells'][$column['key']])
                        <td class="p-3 text-right">@if($cell['amount'] || $cell['hours'] || $cell['quantity'])
                            <span class="font-semibold">{{ $money($cell['amount']) }}</span><p class="text-gray-500">{{ $column['group'] === 'hourly' ? rtrim(rtrim(number_format($cell['hours'], 2, '.', ''), '0'), '.').' hrs' : rtrim(rtrim(number_format($cell['quantity'], 3, '.', ''), '0'), '.').' '.$column['unit'] }}</p>
                        @else<span class="text-gray-400">—</span>@endif</td>
                    @endforeach<td class="p-3 text-right font-semibold">{{ $money($row['total']) }}</td></tr>
                @endforeach</tbody>
                <tfoot class="border-t-2 bg-gray-50 font-semibold"><tr><td class="p-3">Total · {{ count($report['staff']['rows']) }} staff</td>@foreach($report['staff']['columns'] as $column)<td class="p-3 text-right">{{ $money($report['staff']['column_totals'][$column['key']]['amount']) }}</td>@endforeach<td class="p-3 text-right">{{ $money($report['totals']['staff']) }}</td></tr></tfoot>
            </table></div>
            @endif
        @else
            <h3 class="text-lg font-semibold">Other Expense · Vehicles &amp; Materials</h3><p class="text-sm text-gray-500 mt-1 mb-4">Charges billed to or paid by Alam Cocos, including payments for other companies. Each charge is counted once.</p>
            <div class="overflow-x-auto"><table class="w-full text-sm text-left">
                <thead class="bg-gray-50"><tr>@foreach(['Date','Type','Item','Paid by','Billed to','Qty','Rate','Amount'] as $label)<th class="p-3 whitespace-nowrap">{{ $label }}</th>@endforeach</tr></thead>
                <tbody>@forelse($report['charges'] as $charge)
                    <tr class="border-t"><td class="p-3 whitespace-nowrap">{{ $charge['date'] }}</td><td class="p-3"><span class="rounded-full px-2 py-1 text-xs uppercase {{ $charge['type'] === 'vehicle' ? 'bg-blue-50 text-blue-800' : 'bg-amber-50 text-amber-800' }}">{{ $charge['type'] }}</span></td>
                    <td class="p-3 font-semibold">{{ $charge['item'] }}@if($charge['notes'])<p class="text-gray-500 font-normal">{{ $charge['notes'] }}</p>@endif</td><td class="p-3">{{ $charge['paid_by'] }}</td><td class="p-3">{{ $charge['billed_to'] }}</td><td class="p-3">{{ number_format($charge['quantity'], 3) }}</td><td class="p-3 whitespace-nowrap">{{ $money($charge['rate']) }}</td><td class="p-3 font-semibold whitespace-nowrap">{{ $money($charge['amount']) }}</td></tr>
                @empty<tr><td colspan="8" class="p-6 text-gray-500">No vehicle or material charges for this period.</td></tr>@endforelse</tbody>
                <tfoot class="border-t-2 bg-gray-50 font-semibold"><tr><td colspan="7" class="p-3">Total</td><td class="p-3 whitespace-nowrap">{{ $money($report['totals']['other']) }}</td></tr></tfoot>
            </table></div>
        @endif
        </section>
        <p class="text-sm text-gray-500 mt-3">Staff Expense contains the Cost by Task breakdown. It is counted once in the total. Other Expense includes charges paid for other companies; dashboard figures count costs billed to Alam Cocos.</p>
    @else
        <div class="bg-white border rounded-xl p-6"><h3 class="font-semibold">Expenses is not connected for this factory</h3><p class="text-sm text-gray-500 mt-2">The Alam Cocos report is unavailable. Please try again later.</p></div>
    @endif
</x-app-layout>



