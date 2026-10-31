<x-app-layout>
    @include('Pulse.livewire.reports.inventory.partials.inventory-report-header', ['activeTab' => 'expenses', 'startDate' => $from, 'endDate' => $to, 'presetLabel' => request('preset', 'Custom')])
    <div class="py-5 space-y-5" id="expense-report-page">
        <div class="flex justify-between items-center flex-wrap gap-4">
            <div><h2 class="text-2xl font-semibold text-gray-900">Expense Report</h2><p class="text-sm text-gray-500 mt-1">Alam Cocos &middot; {{ \Carbon\Carbon::parse($from)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</p></div>
            @if($report)
            <div class="flex gap-3">
                <a href="{{ route('reports.expenses', ['team' => $team->id, 'from' => $from, 'to' => $to, 'timezone' => $timezone, 'category' => $category, 'export' => 'pdf']) }}" class="border border-gray-300 rounded-xl px-4 py-2 bg-white font-semibold">Export PDF</a>
                <button id="expense-export-image" type="button" class="rounded-xl px-4 py-2 text-white font-semibold" style="background:#83211b">Export as image</button>
            </div>
            @endif
        </div>
        @if($report)
        <div id="expense-report-capture" class="bg-white rounded-xl space-y-5">
            <div data-export-heading style="display:none">Expense Report &middot; Alam Cocos &middot; {{ $from }} &ndash; {{ $to }} &middot; {{ $timezone }}</div>
            @php
                $summary = [
                    ['label' => 'Total cost', 'amount' => $report['totals']['total'], 'color' => '#075e50'],
                    ['label' => 'Hourly', 'amount' => $report['totals']['staff'] - $activityWages, 'color' => '#075e50'],
                    ['label' => 'Activity', 'amount' => $activityWages, 'color' => '#b68017'],
                    ['label' => 'Materials & vehicles', 'amount' => $report['totals']['other'], 'color' => '#557fa8'],
                ];
            @endphp
            <div class="expense-summary-strip">
                @foreach($summary as $item)
                    <div class="expense-summary-card">
                        <p class="expense-summary-label">@if(!$loop->first)<span class="expense-category-dot" style="background:{{ $item['color'] }}" aria-hidden="true"></span>@endif{{ $item['label'] }}</p>
                        <p class="expense-summary-amount" @if($loop->first) style="color:#075e50" @endif>{{ \App\Services\NumberFormatterService::formatToIndianCurrency($item['amount'], 0) }}</p>
                        @if($loop->first)
                            @php
                                $breakdownText = collect(array_slice($summary, 1))->map(function ($segment) use ($report) {
                                    $percent = $report['totals']['total'] > 0 ? max(0, $segment['amount']) / $report['totals']['total'] * 100 : 0;
                                    return $segment['label'].': '.\App\Services\NumberFormatterService::formatToIndianCurrency($segment['amount'], 0).' ('.number_format($percent, 1).'%)';
                                })->implode("\n");
                            @endphp
                            <div class="expense-cost-bar" role="img" tabindex="0" title="{{ $breakdownText }}" aria-label="{{ $breakdownText }}">
                                @foreach(array_slice($summary, 1) as $segment)
                                    @php($share = $report['totals']['total'] > 0 ? max(0, $segment['amount']) / $report['totals']['total'] * 100 : 0)
                                    <span data-cost-color="{{ $segment['color'] }}" style="width:{{ $share }}%;background:{{ $segment['color'] }}"></span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <form method="GET" class="expense-filter-bar flex items-center justify-between gap-3 flex-wrap">
                <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}"><input type="hidden" name="timezone" value="{{ $timezone }}">
                <div class="flex gap-3 items-center"><label for="expense-category" class="font-semibold">Category</label>
                <select id="expense-category" name="category" onchange="this.form.submit()" class="rounded-lg border-gray-300">
                    @foreach(['all' => 'All expenses', 'staff' => 'Staff', 'activity' => 'Activity wages', 'vehicle' => 'Vehicles', 'material' => 'Materials'] as $key => $label)<option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>@endforeach
                </select><noscript><button>Apply</button></noscript></div>
                <span class="text-sm text-gray-500">{{ count($rows) }} entries</span>
            </form>
            @include('pulse::expenses.report-table')
            <p class="text-sm text-gray-500">Includes charges billed to Alam Cocos. Staff costs are counted once. Open-week figures may change.</p>
        </div>
        @else
            <div class="rounded-xl border bg-white p-6"><h3 class="font-semibold">Expenses is not connected for this factory</h3><p class="text-sm text-gray-500 mt-2">The Alam Cocos report is unavailable. Please try again later.</p></div>
        @endif
        <p id="expense-export-status" role="status" class="text-sm text-red-700"></p>
    </div>
    <style>
    #expense-report-page{padding:12px 16px;}
    #expense-report-page > * + *, #expense-report-capture > * + *{margin-top:14px;}
    .expense-cost-bar{display:flex;height:4px;max-width:180px;margin-top:10px;border-radius:999px;overflow:hidden;background:#edf0f4;}
    .expense-cost-bar span{display:block;height:100%;}
    #expense-report-page select{background:#fff;border:1px solid #dce0e7;border-radius:10px;padding:9px 36px 9px 12px;font-size:13px;color:#26344b;}
    #expense-report-page .expense-summary-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:1px;background:#e1e5ec;border:1px solid #e1e5ec;border-radius:16px;overflow:hidden;}
    #expense-report-page .expense-summary-card{padding:16px 20px;min-width:0;background:#fff;}
    #expense-report-page .expense-summary-card:last-child{border-right:0;}
    #expense-report-page .expense-summary-label{display:flex;align-items:center;gap:8px;font-size:14px;color:#64748b;}
    #expense-report-page .expense-category-dot{width:9px;height:9px;border-radius:2px;flex-shrink:0;}
    #expense-report-page .expense-summary-amount{font-size:clamp(22px,2.3vw,28px);overflow-wrap:anywhere;line-height:1.25;font-weight:600;font-variant-numeric:tabular-nums;margin-top:6px;color:#101c30;}
    #expense-report-page .expense-filter-bar{padding:8px 12px;background:#f8f9fb;border:1px solid #e7eaf0;border-radius:12px;}
    #expense-report-page table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#617086;}
    #expense-report-page table tbody tr:hover{background:#fafbfc;}
    #expense-report-page table td{font-size:13px;}
    @media(max-width:640px){#expense-report-page{padding:16px 8px;}#expense-report-page .expense-summary-card p:nth-child(2){font-size:23px;}}
    </style>
    <script src="{{ asset('js/html2canvas.min.js') }}"></script>
    <script>
    (() => {
        const base = @js(route('reports.expenses', ['team' => $team->id]));
        const selectedZone = @js($timezone);
        function navigate(from, to, preset, zone = selectedZone) {
            const url = new URL(base, location.origin);
            Object.entries({from, to, timezone:zone, category:@js($category), preset:preset || 'Custom'}).forEach(([k,v]) => url.searchParams.set(k,v));
            location.assign(url);
        }
        function today(zone) {
            const parts = new Intl.DateTimeFormat('en-CA', {timeZone:zone, year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
            const part = k => parts.find(p => p.type === k).value;
            return new Date(`${part('year')}-${part('month')}-${part('day')}T12:00:00Z`);
        }
        function range(preset, zone, from, to) {
            const key = String(preset).toLowerCase();
            if (key.includes('custom')) return [from,to];
            let end = today(zone), start = new Date(end);
            if(key === 'yesterday') { start.setUTCDate(start.getUTCDate()-1); end = new Date(start); }
            else if(key === '7d' || key.includes('7 day')) start.setUTCDate(start.getUTCDate()-6);
            else if(key === '1m' || key.includes('month')) start.setUTCMonth(start.getUTCMonth()-1);
            else if(key === 'ytd' || key.includes('year')) start = new Date(Date.UTC(end.getUTCFullYear(),0,1,12));
            else if(key !== 'today') return [from,to];
            return [start.toISOString().slice(0,10),end.toISOString().slice(0,10)];
        }
        window.addEventListener('date-range-updated', event => {
            const d = event.detail;
            const dates = range(d.preset, selectedZone, d.startDate, d.endDate);
            navigate(...dates, d.preset);
        });
        document.getElementById('expense-export-image')?.addEventListener('click', async event => {
            const button = event.currentTarget; button.disabled = true;
            try {
                await document.fonts.ready;
                const canvas = await html2canvas(document.getElementById('expense-report-capture'), {scale:2, backgroundColor:'#ffffff', onclone: doc => { doc.querySelector('[data-export-heading]').style.display='block'; doc.querySelectorAll('*').forEach(el => {el.style.color='#17233b';el.style.backgroundColor='#ffffff';el.style.borderColor='#e5e7eb';el.style.boxShadow='none';el.style.textShadow='none';el.style.backgroundImage='none';el.style.outlineColor='#17233b';}); doc.querySelectorAll('[data-cost-color]').forEach(el => {el.style.backgroundColor=el.dataset.costColor;}); doc.querySelectorAll('#expense-report-capture th, #expense-report-capture tfoot td').forEach(el => el.style.backgroundColor='#f3f4f6'); doc.querySelectorAll('#expense-report-capture .overflow-x-auto').forEach(el => {el.style.overflow='visible';}); }});
                const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
                if (!blob) throw new Error('Image unavailable');
                const link = document.createElement('a'); link.download = 'alam-cocos-expenses-{{ $from }}-{{ $to }}.png'; link.href = URL.createObjectURL(blob); document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(link.href), 10000);
            } catch(error) { document.getElementById('expense-export-status').textContent = 'Image export failed. Please try again.'; }
            finally { button.disabled = false; }
        });
    })();
    </script>
</x-app-layout>
