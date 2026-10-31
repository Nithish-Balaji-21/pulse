@include('Pulse.livewire.reports.inventory.partials.inventory-report-header', [
    'title' => 'Pith Blocks Inventory',
    'activeTab' => 'blocks',
])

<div class="pt-6 pb-4 ps-2 sm:ps-3 lg:ps-4 pe-3 space-y-5">
    {{-- Submodule Title & Export Buttons --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pb-3 border-b border-gray-200/70">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Pith Blocks Inventory</h1>

        <div class="flex flex-wrap items-center gap-3 sm:justify-end">
            <button type="button" wire:click="exportPdf" class="px-4 py-2 rounded-xl border border-gray-300 text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 shadow-xs flex items-center gap-2">
                <i class="fa-solid fa-file-pdf text-red-600"></i>
                <span>Export PDF</span>
            </button>
            <button type="button" wire:click="sendSummaryCard" class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-[#7a221e] hover:bg-[#641b18] shadow-xs flex items-center gap-2">
                <i class="fa-solid fa-image text-sm"></i>
                <span>Export as image</span>
            </button>
        </div>
    </div>


    <!-- 5 KPI Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
        <div class="bg-white rounded-2xl p-4 border border-gray-200/70 shadow-xs">
            <span class="caps-label">OPENING</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 mt-1">{{ number_format($reportData['grand_total']['opening_stock'] ?? 0) }} <span class="text-sm font-normal text-gray-500">blocks</span></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border border-gray-200/70 shadow-xs">
            <span class="caps-label">PRODUCED</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight text-emerald-600 mt-1">+{{ number_format($reportData['grand_total']['inward'] ?? 0) }} <span class="text-sm font-normal text-gray-500">blocks</span></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border border-gray-200/70 shadow-xs">
            <span class="caps-label">CORRECTION</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight {{ ($reportData['grand_total']['correction'] ?? 0) < 0 ? 'text-rose-600' : 'text-amber-700' }} mt-1">
                {{ ($reportData['grand_total']['correction'] ?? 0) > 0 ? '+' : '' }}{{ number_format($reportData['grand_total']['correction'] ?? 0) }} <span class="text-sm font-normal text-gray-500">blocks</span>
            </p>
        </div>
        <div class="bg-white rounded-2xl p-4 border border-gray-200/70 shadow-xs">
            <span class="caps-label">SOLD</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight text-blue-600 mt-1">-{{ number_format($reportData['grand_total']['outward'] ?? 0) }} <span class="text-sm font-normal text-gray-500">blocks</span></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border-2 border-amber-500/80 shadow-xs">
            <span class="caps-label">IN STOCK</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 mt-1">{{ number_format($reportData['grand_total']['closing_stock'] ?? 0) }} <span class="text-sm font-normal text-gray-500">blocks</span></p>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-2xl border border-gray-200/80 overflow-hidden shadow-xs">
        <table class="w-full text-left border-collapse text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50/50 caps-label">
                    <th class="py-3 px-4">DAY</th>
                    <th class="py-3 px-4 text-right">OPENING</th>
                    <th class="py-3 px-4 text-right">PRODUCED</th>
                    <th class="py-3 px-4 text-right">CORRECTION</th>
                    <th class="py-3 px-4 text-right">SOLD</th>
                    <th class="py-3 px-4 text-right">IN STOCK</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 tabular-nums">
                @forelse ($reportData['rows'] ?? [] as $row)
                    <tr>
                        <td class="py-2.5 px-4 text-gray-900">{{ $row['name'] }}</td>
                        <td class="py-2.5 px-4 text-right">{{ number_format($row['opening_stock']) }}</td>
                        <td class="py-2.5 px-4 text-right text-emerald-700">{{ $row['inward'] > 0 ? '+' . number_format($row['inward']) : '0' }}</td>
                        <td class="py-2.5 px-4 text-right">
                            @if (!empty($row['correction']) && $row['correction'] != 0)
                                <span class="inline-flex items-center gap-1 {{ $row['correction'] > 0 ? 'text-amber-700' : 'text-rose-600' }}" title="{{ $row['correction_comment'] ?? 'Stock adjustment' }}">
                                    {{ ($row['correction'] > 0 ? '+' : '') . number_format($row['correction']) }}
                                    <i class="fa-solid fa-circle-info text-xs opacity-70"></i>
                                </span>
                            @else
                                <span class="text-gray-500">0</span>
                            @endif
                        </td>
                        <td class="py-2.5 px-4 text-right text-blue-700">{{ $row['outward'] > 0 ? '-' . number_format($row['outward']) : '0' }}</td>
                        <td class="py-2.5 px-4 text-right font-semibold text-gray-900">{{ number_format($row['closing_stock']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-gray-500">No stock movement recorded for selected period.</td>
                    </tr>
                @endforelse
                
                <!-- Total Row -->
                <tr class="font-semibold text-gray-900 bg-gray-50 border-t-2 border-gray-200">
                    <td class="py-3 px-4">Total</td>
                    <td class="py-3 px-4 text-right">{{ number_format($reportData['grand_total']['opening_stock'] ?? 0) }}</td>
                    <td class="py-3 px-4 text-right text-emerald-800">{{ number_format($reportData['grand_total']['inward'] ?? 0) }}</td>
                    <td class="py-3 px-4 text-right text-amber-800">{{ ($reportData['grand_total']['correction'] ?? 0) > 0 ? '+' : '' }}{{ number_format($reportData['grand_total']['correction'] ?? 0) }}</td>
                    <td class="py-3 px-4 text-right text-blue-800">{{ number_format($reportData['grand_total']['outward'] ?? 0) }}</td>
                    <td class="py-3 px-4 text-right">{{ number_format($reportData['grand_total']['closing_stock'] ?? 0) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Off-screen standalone card layout captured for image export --}}
    <div id="report-summary-card-image-source" style="display: none;">
        @include('Pulse.livewire.reports.exports.inventory-report-image', [
            'report' => $reportData,
            'teamName' => auth()->user()->activeTeam()?->name ?? auth()->user()->currentTeam->name ?? 'CoiioT'
        ])
    </div>
</div>

@push('scripts')
    <script src="/js/report-export.js"></script>
@endpush
