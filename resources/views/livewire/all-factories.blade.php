<div>
    @php
        $currentTeamId = auth()->user()->currentTeam->id ?? 1;
        $totalFactoriesCount = \App\Models\Team::count();
        $totalUsersCount = \App\Models\User::count();
        $activeUsersCount = \App\Models\User::where('is_active', 1)->count();
        $inactiveUsersCount = \App\Models\User::where('is_active', 0)->count();
    @endphp

    <!-- Top Summary Stat Cards (Compact Horizontal Layout Shifted Right) -->
    <div class="flex justify-end px-4 pt-2 mb-3">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 max-w-5xl w-full">
            <!-- Total Factories Card -->
            <div class="bg-amber-50/60 border-t border-r border-b border-amber-200/70 border-l-4 border-l-amber-500 rounded-r-xl rounded-l-xs py-2 px-3 flex items-center gap-2.5 shadow-2xs hover:shadow-xs transition">
                <div class="w-7 h-7 rounded-full bg-amber-100/90 flex items-center justify-center text-amber-700 shrink-0 shadow-2xs">
                    <i class="fa-solid fa-industry text-xs"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-1">
                        <p class="caps-label text-amber-900/80 truncate">Factories</p>
                        <h3 class="text-base font-semibold tabular-nums text-gray-900 leading-none">{{ $totalFactoriesCount }}</h3>
                    </div>
                    <p class="text-xs text-amber-700/90 font-medium truncate">Active factories</p>
                </div>
            </div>

            <!-- Total Users Card -->
            <div class="bg-rose-50/60 border-t border-r border-b border-rose-200/70 border-l-4 border-l-rose-500 rounded-r-xl rounded-l-xs py-2 px-3 flex items-center gap-2.5 shadow-2xs hover:shadow-xs transition">
                <div class="w-7 h-7 rounded-full bg-rose-100/90 flex items-center justify-center text-rose-700 shrink-0 shadow-2xs">
                    <i class="fa-solid fa-users text-xs"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-1">
                        <p class="caps-label text-rose-900/80 truncate">Users</p>
                        <h3 class="text-base font-semibold tabular-nums text-gray-900 leading-none">{{ $totalUsersCount }}</h3>
                    </div>
                    <p class="text-xs text-rose-700/90 font-medium truncate">Across all factories</p>
                </div>
            </div>

            <!-- Active Users Card -->
            <div class="bg-emerald-50/60 border-t border-r border-b border-emerald-200/70 border-l-4 border-l-emerald-500 rounded-r-xl rounded-l-xs py-2 px-3 flex items-center gap-2.5 shadow-2xs hover:shadow-xs transition">
                <div class="w-7 h-7 rounded-full bg-emerald-100/90 flex items-center justify-center text-emerald-700 shrink-0 shadow-2xs">
                    <i class="fa-solid fa-user-check text-xs"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-1">
                        <p class="caps-label text-emerald-900/80 truncate">Active</p>
                        <h3 class="text-base font-semibold tabular-nums text-gray-900 leading-none">{{ $activeUsersCount }}</h3>
                    </div>
                    <p class="text-xs text-emerald-700/90 font-medium truncate">Active accounts</p>
                </div>
            </div>

            <!-- Inactive Users Card -->
            <div class="bg-indigo-50/60 border-t border-r border-b border-indigo-200/70 border-l-4 border-l-indigo-500 rounded-r-xl rounded-l-xs py-2 px-3 flex items-center gap-2.5 shadow-2xs hover:shadow-xs transition">
                <div class="w-7 h-7 rounded-full bg-indigo-100/90 flex items-center justify-center text-indigo-700 shrink-0 shadow-2xs">
                    <i class="fa-solid fa-user-clock text-xs"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-1">
                        <p class="caps-label text-indigo-900/80 truncate">Inactive</p>
                        <h3 class="text-base font-semibold tabular-nums text-gray-900 leading-none">{{ $inactiveUsersCount }}</h3>
                    </div>
                    <p class="text-xs text-indigo-700/90 font-medium truncate">Inactive accounts</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Header + Filter Bar -->
    <div class="flex flex-wrap justify-between items-center my-4 px-4 gap-3">
        <h2 class="text-lg font-semibold text-gray-800">Factory Details</h2>

        <div class="flex flex-wrap items-center gap-2">
            <input type="text" wire:model="searchName"
                   placeholder="Factory name..."
                   class="w-36 form-input border border-gray-300 rounded-lg text-xs px-3 py-2 focus:ring-1 focus:ring-blue-500">
            <input type="text" wire:model="searchAddress"
                   placeholder="City / ZIP..."
                   class="w-32 form-input border border-gray-300 rounded-lg text-xs px-3 py-2 focus:ring-1 focus:ring-blue-500">
            <select wire:model="searchModule"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-xs text-gray-700 bg-white focus:ring-1 focus:ring-blue-500">
                <option value="">All Modules</option>
                <option value="has_pith_module">Pith</option>
                <option value="has_fibre_module">Fibre</option>
                <option value="has_growbags_module">Growbag</option>
                <option value="has_attendance_module">Staff Attendance</option>
                <option value="has_geofencing_module">Geofencing</option>
            </select>
            <select wire:model="searchStatus"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-xs text-gray-700 bg-white focus:ring-1 focus:ring-blue-500">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <button wire:click="doSearch"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-semibold shadow-xs transition">
                Search
            </button>
            @if($searchName || $searchAddress || $searchModule || $searchStatus || $sortColumn !== 'is_active' || $sortDirection !== 'desc')
                <button wire:click="resetSearch"
                        class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-2 rounded-lg text-xs font-medium transition">
                    Reset
                </button>
            @endif
        </div>
    </div>

    <!-- Factories Table Card -->
    <div class="px-4 pb-8">
        <div class="bg-white border border-gray-200 border-l-4 border-l-amber-500 rounded-2xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50/90 caps-label">
                        <tr>
                            @php
                                $columns = [
                                    ['col' => 'name',             'label' => 'Name & Address'],
                                    ['col' => 'shift_start_time', 'label' => 'Shift Start'],
                                    ['col' => 'main_module',      'label' => 'Main Module'],
                                    ['col' => '',                 'label' => 'Sub Modules'],
                                    ['col' => '',                 'label' => 'Status'],
                                    ['col' => 'license_valid_till',    'label' => 'Valid Till'],
                                    ['col' => 'users_count',      'label' => 'Users'],
                                    ['col' => 'staffs_count',     'label' => 'Staff'],
                                    ['col' => 'created_at',       'label' => 'Created'],
                                ];
                            @endphp
                            @foreach($columns as $item)
                                @if($item['col'])
                                    <th class="px-5 py-3 text-left whitespace-nowrap">
                                        <a wire:click.prevent="sortBy('{{ $item['col'] }}')" href="#"
                                           class="flex items-center gap-1 hover:text-[#83211b] cursor-pointer">
                                            {{ $item['label'] }}
                                            <span class="text-xs {{ $sortColumn === $item['col'] ? 'text-[#83211b]' : 'text-gray-400' }}">
                                               @if($sortColumn === $item['col'])
                                                     <i class="fa fa-caret-{{ $sortDirection === 'asc' ? 'up' : 'down' }}"></i>
                                                @else
                                                     <i class="fa-solid fa-sort"></i>
                                                @endif
                                            </span>
                                        </a>
                                    </th>
                                @else
                                    <th class="px-5 py-3 text-left whitespace-nowrap">{{ $item['label'] }}</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse($factories as $factory)
                            <tr class="hover:bg-amber-50/20 transition-colors">
                                <!-- Factory Name & Address -->
                                <td class="px-5 py-3.5 text-gray-900">
                                    <div>
                                        <a href="{{ route(\'allusers.index\', ['team' => $currentTeamId, 'factory' => $factory['id']]) }}"
                                           class="text-[#83211b] font-semibold hover:underline flex items-center gap-1.5">
                                            <i class="fa-solid fa-industry text-amber-600 text-xs"></i>
                                            {{ $factory['name'] }}
                                        </a>
                                    </div>
                                    @if(!empty($factory['address']))
                                        <div class="text-xs text-gray-500 mt-0.5">{{ $factory['address'] }}</div>
                                    @endif
                                    @if(!empty($factory['coordinates']))
                                        <div class="text-xs text-gray-500 font-mono">{{ $factory['coordinates'] }}</div>
                                    @endif
                                </td>

                                <!-- Shift Start -->
                                <td class="px-5 py-3.5 text-center text-sm tabular-nums text-gray-900 whitespace-nowrap">
                                    {{ $factory['shift_start_time'] ?? '-' }}
                                </td>

                                <!-- Main Module -->
                                <td class="px-5 py-3.5 text-center">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200">
                                        {{ $factory['main_module'] }}
                                    </span>
                                </td>

                                <!-- Sub Modules -->
                                <td class="px-5 py-3.5">
                                    @if(!empty($factory['other_modules']))
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($factory['other_modules'] as $module)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-700 border border-gray-200">
                                                    {{ $module }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-center text-sm text-gray-500">-</div>
                                    @endif
                                </td>

                                <!-- Status -->
                                <td class="px-5 py-3.5 text-center">
                                    @if($factory['status'] === 'Active')
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span> Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-full bg-rose-100 text-rose-800 border border-rose-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span> Inactive
                                        </span>
                                    @endif
                                </td>

                                <!-- Valid Till -->
                                <td class="px-5 py-3.5 text-center text-sm tabular-nums text-gray-900 whitespace-nowrap">
                                    {{ $factory['validity'] ?? '-' }}
                                </td>

                                <!-- Users Count (Clickable to jump to users) -->
                                <td class="px-5 py-3.5 text-center">
                                    <a href="{{ route('allusers.index', ['team' => $currentTeamId, 'factory' => $factory['id']]) }}"
                                       class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold tabular-nums bg-blue-50 text-blue-700 hover:bg-blue-100 transition-colors">
                                        {{ $factory['users'] }} users
                                    </a>
                                </td>

                                <!-- Staff Count -->
                                <td class="px-5 py-3.5 text-center text-sm tabular-nums text-gray-900">
                                    {{ $factory['staff'] }}
                                </td>

                                <!-- Created Date -->
                                <td class="px-5 py-3.5 text-center text-sm tabular-nums text-gray-900 whitespace-nowrap">
                                    {{ $factory['created'] }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-6 py-12 text-center text-gray-500">No factories found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="p-4 border-t border-gray-100">
                <x-pagination :items="$factories" perPageBinding="perPage" />
            </div>
        </div>
    </div>
</div>
