@include('Pulse.livewire.reports.inventory.partials.inventory-report-header', [
    'title' => 'Raw Pith Inventory',
    'activeTab' => 'pith',
])

<div class="pt-6 pb-4 ps-2 sm:ps-3 lg:ps-4 pe-3 space-y-5">
    {{-- Submodule Title & Export Buttons --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pb-3 border-b border-gray-200/70">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Raw Pith Inventory</h1>

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


    <!-- 4 KPI Summary Cards -->
    <div class="grid grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl p-4 border border-gray-200/70 shadow-xs">
            <span class="caps-label">OPENING</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 mt-1">{{ number_format($reportData['grand_total']['opening_stock'] ?? 0) }} <span class="text-sm font-normal text-gray-500">CFT</span></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border border-gray-200/70 shadow-xs">
            <span class="caps-label">INWARD</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight text-red-600 mt-1">+{{ number_format($reportData['grand_total']['inward'] ?? 0) }} <span class="text-sm font-normal text-gray-500">CFT</span></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border border-gray-200/70 shadow-xs">
            <span class="caps-label">OUTWARD</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight text-blue-600 mt-1">-{{ number_format($reportData['grand_total']['outward'] ?? 0) }} <span class="text-sm font-normal text-gray-500">CFT</span></p>
        </div>
        <div class="bg-white rounded-2xl p-4 border-2 border-amber-500/80 shadow-xs">
            <span class="caps-label">CLOSING STOCK</span>
            <p class="text-2xl font-semibold tabular-nums tracking-tight text-gray-900 mt-1">{{ number_format($reportData['grand_total']['closing_stock'] ?? 0) }} <span class="text-sm font-normal text-gray-500">CFT</span></p>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-2xl border border-gray-200/80 overflow-hidden shadow-xs">
        <table class="w-full text-left border-collapse text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50/50 caps-label">
                    <th class="py-3 px-4">LOCATION GROUP</th>
                    <th class="py-3 px-4 text-right">OPENING</th>
                    <th class="py-3 px-4 text-right">INWARD</th>
                    <th class="py-3 px-4 text-right">OUTWARD</th>
                    <th class="py-3 px-4 text-right">CLOSING</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 tabular-nums">
                @forelse ($reportData['groups'] ?? [] as $group)
                    <!-- Group Header Row -->
                    <tr class="font-semibold text-gray-900 bg-gray-50/40">
                        <td class="py-2.5 px-4 font-semibold text-gray-900">
                            <span>{{ $group['name'] }}</span>
                            <span class="text-sm font-normal text-gray-500 ml-1">({{ $group['location_count'] }})</span>
                        </td>
                        <td class="py-2.5 px-4 text-right">{{ number_format($group['subtotal']['opening_stock']) }}</td>
                        <td class="py-2.5 px-4 text-right text-red-600">+{{ number_format($group['subtotal']['inward']) }}</td>
                        <td class="py-2.5 px-4 text-right text-blue-600">-{{ number_format($group['subtotal']['outward']) }}</td>
                        <td class="py-2.5 px-4 text-right text-gray-900">{{ number_format($group['subtotal']['closing_stock']) }}</td>
                    </tr>

                    <!-- Nested Location Rows -->
                    @foreach ($group['locations'] as $loc)
                        <tr class="text-gray-900 hover:bg-gray-50/50">
                            <td class="py-2 px-4 ps-8 flex items-center gap-2">
                                <span>{{ $loc['name'] }}</span>
                                @if(!empty($loc['was_closed_in_range']))
                                    <span class="px-1.5 py-0.5 rounded-md bg-gray-100 text-xs font-semibold text-gray-500">Closed</span>
                                @endif
                            </td>
                            <td class="py-2 px-4 text-right">{{ number_format($loc['opening_stock']) }}</td>
                            <td class="py-2 px-4 text-right">{{ number_format($loc['inward']) }}</td>
                            <td class="py-2 px-4 text-right">{{ number_format($loc['outward']) }}</td>
                            <td class="py-2 px-4 text-right font-semibold text-gray-900">{{ number_format($loc['closing_stock']) }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center text-gray-500">No raw pith location records found for selected period.</td>
                    </tr>
                @endforelse

                <!-- Grand Total Row -->
                <tr class="font-semibold text-gray-900 bg-gray-50 border-t-2 border-gray-200">
                    <td class="py-3 px-4">Total</td>
                    <td class="py-3 px-4 text-right">{{ number_format($reportData['grand_total']['opening_stock'] ?? 0) }}</td>
                    <td class="py-3 px-4 text-right">{{ number_format($reportData['grand_total']['inward'] ?? 0) }}</td>
                    <td class="py-3 px-4 text-right">{{ number_format($reportData['grand_total']['outward'] ?? 0) }}</td>
                    <td class="py-3 px-4 text-right">{{ number_format($reportData['grand_total']['closing_stock'] ?? 0) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Off-screen standalone card layout captured for WhatsApp image export --}}
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
