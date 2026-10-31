{{--
    Top rule: the summary table carries a border-t under the page heading, so
    the detail panels need the same line or the heading bleeds into the panel
    headers. Matches the bordered panel container on the inventory screen.

    Height is the viewport minus the 50px sticky nav (same figure the sidebar in
    coiiot-menu uses) and the container is overflow-hidden, so the page itself
    never scrolls — the only scrollbars are the two panels' own. Each panel is a
    flex column whose header rows are shrink-0, which pins them while its body
    scrolls underneath.
--}}
<div class="flex h-[calc(100vh-50px)] overflow-hidden border-t border-gray-200">
    <!-- Left Panel: Production List -->
    <div class="w-96 shrink-0 flex flex-col border-r border-gray-200 bg-white shadow-xs transition-all duration-300 ease-in-out">
        <div class="w-full h-16 shrink-0 flex place-content-between border-b border-gray-200 items-center px-6 bg-gradient-to-r from-gray-50 to-gray-100">
            <div class="flex items-center gap-3">
                <div class="bg-gradient-to-br from-[#861e1a] to-[#a82824] p-2 rounded-lg">
                    <x-menu-icon name="pith-production" :size="20" class="text-white" />
                </div>
                <h2 class="text-lg font-semibold text-gray-800 whitespace-nowrap">{{ __('Pith Production') }}</h2>
            </div>
            <div class="flex gap-2 items-center">
                @if (Auth::user()->allows('pith_production', 'create') && $showActuals)
                    <a href="{{ route('pith.create', ['team' => $teamId]) }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gradient-to-r from-[#861e1a] to-[#a82824] text-white text-sm font-medium rounded-lg hover:shadow-md transition-all">
                        <i class="fa-solid fa-plus text-xs"></i>
                        <span>New</span>
                    </a>
                @endif
            </div>
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto">
            <ul class="divide-y divide-gray-200">
                @foreach ($productions as $production)
                    <li class="cursor-pointer px-6 py-4 transition-all duration-200 {{ $selectedPith->id === $production->id ? 'bg-gradient-to-r from-blue-50 to-indigo-50 border-l-4 border-[#861e1a]' : 'hover:bg-gray-50' }}"
                        wire:click="selectProduction({{ $production->id }})">
                        <div class="flex justify-between gap-3 text-sm">
                            <div class="flex flex-col gap-2">
                                <div class="font-semibold text-gray-800 flex items-center gap-2">
                                    <i class="fa-regular fa-calendar text-[#861e1a] text-xs"></i>
                                    {{ \Carbon\Carbon::parse($production->shift_date)->format($dateFormat) }}
                                </div>
                                @if ($production->loads_produced > 0)
                                    <div class="flex items-center gap-2 text-gray-600">
                                        <span class="font-medium text-[#861e1a]">{{ \App\Services\NumberFormatterService::formatCompactNumber($production->loads_produced) }}</span>
                                        <span class="text-xs text-gray-500">cft</span>
                                        <span class="text-gray-300">•</span>
                                        <span class="text-xs">{{ $production->staff ? $production->staff->staff_name : '' }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="text-right">
                                <div class="text-xs text-gray-600 mb-1">
                                    {{ \Carbon\Carbon::parse($production->start_time)->format($timeFormat) }}
                                    <span class="text-gray-400">-</span>
                                    {{ \Carbon\Carbon::parse($production->end_time)->roundMinute()->format($timeFormat) }}
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    {{ $production->shift ? $production->shift->name : '-' }}
                                </span>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
            <!-- Pagination Controls -->
            <div class="border-t border-gray-200 bg-gray-50">
                <x-pagination :items="$productions" perPageBinding="perPage" />
            </div>
        </div>
    </div>

    <!-- Right Panel: Production Details -->
    <div class="flex-1 min-w-0 flex flex-col bg-gradient-to-br from-gray-50 to-white transition-all duration-300 ease-in-out overflow-hidden">
        <div class="flex shrink-0 place-content-between h-16 w-full px-6 border-b border-gray-200 items-center bg-white shadow-xs">
            <h2 class="text-lg font-semibold text-gray-800">Production Details</h2>
            <button wire:click="backToTable" class="p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-all">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        <div class="w-full h-12 shrink-0 border-b border-gray-200 shadow-xs flex place-content-between place-items-center bg-white">
            <div class="flex h-full divide-x divide-gray-200">
                @if (Auth::user()->allows('pith_production', 'edit'))
                    @if ($selectedPith->canEditRecord())
                        <a href="{{ route('pith.edit', ['team' => $teamId, 'id' => $selectedPith->id]) }}"
                            class="h-full flex items-center gap-2 px-5 text-gray-700 text-sm font-medium cursor-pointer hover:bg-blue-50 hover:text-blue-600 transition-colors">
                            <i class="fa-regular fa-pen-to-square"></i>
                            <span>Edit</span>
                        </a>
                    @else
                        <button type="button" disabled
                            class="h-full flex items-center gap-2 px-5 text-gray-400 text-sm font-medium opacity-50 cursor-not-allowed"
                            title="{{ isset($entryPolicy) ? $entryPolicy->policyMessage() : 'Record can be edited and deleted up to 10 days only.' }}">
                            <i class="fa-regular fa-pen-to-square"></i>
                            <span>Edit</span>
                        </button>
                    @endif
                @endif
                @if (Auth::user()->allows('pith_production', 'assign_to_inventory'))
                    <a href="#"
                        wire:click.prevent="openInventoryModal({{ $selectedPith->id }}, '{{ $selectedPith->shift?->name ?? '' }}')"
                        class="h-full flex items-center gap-2 px-5 text-gray-700 text-sm font-medium cursor-pointer hover:bg-blue-50 hover:text-blue-600 transition-colors">
                        <i class="fa-solid fa-database"></i>
                        <span>Inventory</span>
                    </a>
                @endif
                @if (Auth::user()->allows('pith_production', 'view_device_records'))
                    <a href="{{ route('pith.report', ['team' => $teamId, 'id' => $selectedPith->id]) }}"
                        class="h-full flex items-center gap-2 px-5 text-gray-700 text-sm font-medium cursor-pointer hover:bg-blue-50 hover:text-blue-600 transition-colors">
                        <i class="fas fa-chart-line"></i>
                        <span>Device Report</span>
                    </a>
                @endif
                @if (Auth::user()->allows('pith_production', 'delete'))
                    @if ($selectedPith->canDeleteRecord())
                        <button wire:click="confirmDeletion({{ $selectedPith->id }})"
                            class="h-full flex items-center gap-2 px-5 text-gray-700 text-sm font-medium cursor-pointer hover:bg-red-50 hover:text-red-600 transition-colors">
                            <i class="fa-regular fa-trash-can"></i>
                            <span>Delete</span>
                        </button>
                    @else
                        <button type="button" disabled
                            class="h-full flex items-center gap-2 px-5 text-gray-400 text-sm font-medium opacity-50 cursor-not-allowed"
                            title="{{ isset($entryPolicy) ? $entryPolicy->policyMessage() : 'Record can be edited and deleted up to 10 days only.' }}">
                            <i class="fa-regular fa-trash-can"></i>
                            <span>Delete</span>
                        </button>
                    @endif
                @endif
            </div>
        </div>

        <div class="flex flex-1 min-h-0 p-8 w-full overflow-y-auto space-x-6">
            <!-- Production Summary Card -->
            <div class="w-1/3">
                <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="p-2 bg-gradient-to-br from-[#861e1a] to-[#a82824] rounded-lg">
                            <i class="fa-solid fa-chart-line text-white text-sm"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800">Production Summary</h3>
                    </div>

                    <div class="mb-6">
                        <p class="text-sm text-gray-500 mb-2">Production on</p>
                        <p class="text-2xl font-semibold tabular-nums tracking-tight text-[#861e1a]">
                            {{ \Carbon\Carbon::parse($selectedPith->shift_date)->format($dateFormat) }}
                        </p>
                    </div>

                    <div class="mb-6">
                        @if ($showActuals)
                            <div class="flex items-baseline gap-2 mb-3">
                                <span class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800">{{ \App\Services\NumberFormatterService::formatCompactNumber($selectedPith->loads_produced) }}</span>
                                <span class="text-sm text-gray-500">cft</span>
                            </div>
                            @if ($loadsExpected > 0)
                            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden mb-2">
                                <div class="h-full bg-gradient-to-r from-[#861e1a] to-[#a82824] rounded-full transition-all duration-500"
                                     style="width: {{ min(($selectedPith->loads_produced / $loadsExpected * 100), 100) }}%;"></div>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">0</span>
                                <span class="text-gray-600 font-medium">Estimated: {{ \App\Services\NumberFormatterService::formatToIndianFormat(round($loadsExpected ?? 0), false, 0) }}</span>
                            </div>
                            @endif
                        @else
                            <div class="flex items-baseline gap-2 mb-3">
                                <span class="text-2xl font-semibold tabular-nums tracking-tight text-gray-800">{{ \App\Services\NumberFormatterService::formatCompactNumber($loadsExpected ?? 0) }}</span>
                                <span class="text-sm text-gray-500">cft</span>
                            </div>
                            <div class="text-sm text-gray-500">Estimated</div>
                        @endif
                    </div>

                    <div class="space-y-4 border-t border-gray-200 pt-4">
                        <div class="flex items-start gap-3">
                            <div class="p-2 bg-blue-50 rounded-lg">
                                <i class="fa-solid fa-clock text-blue-600 text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1">Shift</div>
                                <div class="text-gray-900 tabular-nums">{{ $selectedPith->shift?->name }}</div>
                                @if ($selectedPith->start_time && $selectedPith->end_time)
                                    @php
                                        $startTime = \Carbon\Carbon::parse($selectedPith->start_time);
                                        $endTime = \Carbon\Carbon::parse($selectedPith->end_time)->roundMinute();
                                    @endphp
                                    <div class="inline-flex items-center gap-1 px-3 py-1 bg-blue-50 rounded-full text-sm text-blue-700 mt-1">
                                        {{ $startTime->format('g:i a') }} &ndash; {{ $endTime->format('g:i a') }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <div class="p-2 bg-green-50 rounded-lg">
                                <i class="fa-solid fa-user text-green-600 text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1">Staff</div>
                                <div class="text-gray-900 tabular-nums">
                                    {{ $selectedPith->staff ? $selectedPith->staff->staff_name : '-' }}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <div class="p-2 bg-purple-50 rounded-lg">
                                <i class="fa-regular fa-clock text-purple-600 text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1">Production Hours</div>
                                <div class="text-gray-900 tabular-nums">
                                    {{ $selectedPith->load_hrs ? $selectedPith->load_hrs . ' hrs' : '-' }}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <div class="p-2 bg-yellow-50 rounded-lg">
                                <i class="fa-solid fa-bolt text-yellow-600 text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1">EB Units: Total</div>
                                <div class="text-gray-900 tabular-nums">
                                    {{ $selectedPith->eb_units ? round($selectedPith->eb_units, 1) . ' units' : '-' }}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <div class="p-2 bg-orange-50 rounded-lg">
                                <i class="fa-solid fa-bolt text-orange-600 text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1 text-nowrap">EB Cost: Per CFT</div>
                                <div class="text-gray-900 tabular-nums">
                                    {{ $selectedPith->eb_price > 0 && $selectedPith->loads_produced > 0 ? '₹' . number_format($selectedPith->eb_price / $selectedPith->loads_produced, 2) : '-' }}
                                </div>
                            </div>
                        </div>

                        @if($selectedPith->comments)
                        <div class="flex items-start gap-3">
                            <div class="p-2 bg-gray-50 rounded-lg">
                                <i class="fa-solid fa-comment text-gray-600 text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1">Comments</div>
                                <div class="text-sm text-gray-900">{{ $selectedPith->comments }}</div>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Inventory Card -->
            <div class="w-1/3">
                <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="p-2 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg">
                            <i class="fa-solid fa-database text-white text-sm"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800">Inventory</h3>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                <tr class="caps-label border-b border-gray-200">
                                    <th class="p-3 font-semibold text-left">Location</th>
                                    <th class="p-3 font-semibold text-right">CFT</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse ($selectedPithLocations as $location)
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="p-3 text-sm text-gray-900">{{ $location->name }}</td>
                                        <td class="p-3 text-sm text-right tabular-nums text-gray-900">
                                            {{ \App\Services\NumberFormatterService::formatCompactNumber($location->total_loads) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="p-6 text-center">
                                            <div class="flex flex-col items-center gap-2">
                                                <i class="fa-solid fa-inbox text-gray-300 text-3xl"></i>
                                                <p class="text-sm text-gray-500">No cft in inventory</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Device Efficiency Card -->
            <div class="w-1/3">
                <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="p-2 bg-gradient-to-br from-green-500 to-green-600 rounded-lg">
                            <i class="fa-solid fa-gauge-high text-white text-sm"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800">Device Efficiency</h3>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-200">
                        <table class="w-full">
                            <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                <tr class="caps-label border-b border-gray-200">
                                    <th class="p-3 font-semibold text-left">Device State</th>
                                    <th class="p-3 font-semibold text-right">Time</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @php
                                    $states = [
                                        'efficient' => ['label' => 'Efficient', 'color' => 'green'],
                                        'idle'      => ['label' => 'Underloaded', 'color' => 'yellow'],
                                        'overload'  => ['label' => 'Overloaded', 'color' => 'orange'],
                                        'off'       => ['label' => 'Machine Off', 'color' => 'gray'],
                                        'shutdown'  => ['label' => 'Power Off', 'color' => 'red'],
                                        'missing'   => ['label' => 'Data Missing', 'color' => 'purple'],
                                    ];
                                @endphp
                                @foreach ($stateDurations as $state => $duration)
                                    @if ($duration > 0)
                                        <tr class="hover:bg-gray-50 transition-colors">
                                            <td class="p-3 text-sm">
                                                <span class="inline-flex items-center gap-2">
                                                    <span class="w-2 h-2 rounded-full bg-{{ $states[$state]['color'] ?? 'gray' }}-500"></span>
                                                    <span class="text-gray-900">{{ $states[$state]['label'] ?? ucfirst($state) }}</span>
                                                </span>
                                            </td>
                                            <td class="p-3 text-sm text-right tabular-nums text-gray-900">
                                                {{ $duration }}
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Dialog -->
    @if ($showDeleteConfirmationDialog)
        <div class="fixed z-50 inset-0 overflow-y-auto bg-black/50 backdrop-blur-xs">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-md transform transition-all">
                    <div class="p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="p-3 bg-red-100 rounded-full">
                                <i class="fa-solid fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                            <h2 class="text-lg font-semibold text-gray-800">Confirm Deletion</h2>
                        </div>
                        <p class="text-gray-600 mb-6">Are you sure you want to delete this pith production? This action cannot be undone.</p>
                        <div class="flex justify-end gap-3">
                            <button class="px-4 py-2 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg font-medium transition-colors"
                                    wire:click="closeDeleteModal">
                                Cancel
                            </button>
                            <button class="delete-btn shadow-md hover:shadow-lg transition-all"
                                    wire:click="deleteProduction">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
