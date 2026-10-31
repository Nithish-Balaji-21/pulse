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

    <!-- Header + Actions Bar -->
    <div class="flex flex-wrap justify-between items-center my-4 px-4 gap-3">
        <div class="flex items-center gap-3">
            <h2 class="text-lg font-semibold text-gray-800">Manage Users</h2>
            @if ($focusedFactoryId)
                <a href="{{ route('allfactories.index', ['team' => $currentTeamId]) }}"
                   class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 border border-gray-300 transition">
                    <i class="fa-solid fa-arrow-left"></i> Back to All Factories
                </a>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <!-- Add User Button (Redirects to /2/users) -->
            <a href="{{ route('users.index', ['team' => $currentTeamId]) }}"
               class="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg shadow-sm transition flex items-center gap-1.5">
                <i class="fa-solid fa-user-plus text-xs"></i> Add User
            </a>

            <!-- View Switcher -->
            <button wire:click="$set('viewMode', '{{ $viewMode === 'normal' ? 'factory' : 'normal' }}')"
                    class="px-3.5 py-1.5 text-sm font-semibold rounded-lg border transition flex items-center gap-1.5 shadow-sm {{ $viewMode === 'factory' ? 'bg-amber-600 text-white border-amber-600 hover:bg-amber-700' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
                <i class="fa-solid fa-industry text-xs"></i>
                {{ $viewMode === 'normal' ? 'View by Factory' : 'Normal View' }}
            </button>

            @if($viewMode === 'normal')
                <input type="text"
                       wire:model="search"
                       placeholder="Name..."
                       class="w-32 form-input border border-gray-300 rounded-lg text-sm px-3 py-1.5 focus:ring-1 focus:ring-blue-500">
                <input type="text"
                       wire:model="searchEmail"
                       placeholder="Email..."
                       class="w-36 form-input border border-gray-300 rounded-lg text-sm px-3 py-1.5 focus:ring-1 focus:ring-blue-500">
                <input type="text"
                       wire:model="searchMobile"
                       placeholder="Mobile..."
                       class="w-28 form-input border border-gray-300 rounded-lg text-sm px-3 py-1.5 focus:ring-1 focus:ring-blue-500">
                <select wire:model.defer="searchStatus"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 bg-white focus:ring-1 focus:ring-blue-500">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <button wire:click="doSearch"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded-lg text-sm font-medium transition shadow-sm">
                    Search
                </button>
                @if($search || $searchEmail || $searchMobile || $searchStatus || $sortCol)
                    <button wire:click="resetSearch"
                            class="bg-gray-400 hover:bg-gray-500 text-white px-3.5 py-1.5 rounded-lg text-sm font-medium transition">
                        Reset
                    </button>
                @endif
            @endif
        </div>
    </div>

    <!-- Notification Messages -->
    <div class="px-4">
        @if (session()->has('success'))
            <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-800 text-sm font-medium">
                {{ session('success') }}
            </div>
        @endif
        @if (session()->has('error'))
            <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm font-medium">
                {{ session('error') }}
            </div>
        @endif
    </div>

    @if ($viewMode === 'factory')
        <!-- Factory View Top Filter & Header Bar -->
        <div class="bg-white border border-gray-200 border-l-4 border-l-amber-500 rounded-xl shadow-sm p-4 mb-5 mx-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <!-- Multi-select Factory Dropdown -->
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                        class="flex items-center gap-2 px-3.5 py-2 text-sm font-semibold border border-gray-300 rounded-lg bg-gray-50 hover:bg-gray-100 min-w-[240px] justify-between transition">
                        <span class="flex items-center gap-2">
                            <i class="fa-solid fa-industry text-amber-600 text-sm"></i>
                            @if(empty($selectedFactoryIds))
                                All Factories
                            @else
                                {{ count($selectedFactoryIds) }} {{ count($selectedFactoryIds) === 1 ? 'Factory' : 'Factories' }} Selected
                            @endif
                        </span>
                        <i class="fa-solid fa-chevron-down text-xs text-gray-500"></i>
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute top-full left-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl z-50 min-w-[280px] max-h-72 overflow-y-auto py-1">
                        <label class="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-amber-50/60 cursor-pointer border-b border-gray-100">
                            <input type="checkbox"
                                class="rounded border-gray-300 text-amber-600 focus:ring-amber-500"
                                :checked="{{ empty($selectedFactoryIds) ? 'true' : 'false' }}"
                                wire:click="clearFactoryFocus()">
                            <span class="font-semibold text-gray-800">All Factories</span>
                        </label>
                        @foreach($this->factories as $f)
                            <label class="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-amber-50/40 cursor-pointer">
                                <input type="checkbox"
                                    class="rounded border-gray-300 text-amber-600 focus:ring-amber-500"
                                    value="{{ $f->id }}"
                                    wire:click="toggleFactoryId({{ $f->id }})"
                                    {{ in_array($f->id, $selectedFactoryIds) ? 'checked' : '' }}>
                                <span class="font-medium">{{ $f->name }}</span>
                                @if($f->owner)
                                    <span class="ml-auto text-xs text-amber-700 font-semibold bg-amber-50 px-2 py-0.5 rounded">{{ $f->owner->name }}</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                </div>

                @if(!empty($selectedFactoryIds))
                    <button wire:click="clearFactoryFocus()" class="px-3 py-1.5 text-xs text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg font-medium transition">
                        Clear Selection
                    </button>
                @endif

                @if($focusedFactoryId)
                    <a href="{{ route('allfactories.index', ['team' => $currentTeamId]) }}" class="px-3 py-1.5 text-xs text-amber-800 bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded-lg font-semibold transition inline-flex items-center gap-1">
                        <i class="fa-solid fa-arrow-left"></i> All Factories Table
                    </a>
                @endif
            </div>

            <div class="text-xs text-gray-500 font-medium">
                Showing <span class="font-semibold tabular-nums text-gray-800">{{ count($this->factoryUsers) }}</span> {{ count($this->factoryUsers) === 1 ? 'factory' : 'factories' }}
            </div>
        </div>

        <!-- Per-Factory Cards -->
        <div class="px-4 space-y-6 pb-8">
            @forelse($this->factoryUsers as $factoryEntry)
                @php
                    $factory = $factoryEntry['factory'];
                    $groups  = $factoryEntry['groups'];
                    $fid     = (string) $factory->id;
                    $sortCol = $factoryEntry['sortCol'] ?? 'role';
                    $sortDir = $factoryEntry['sortDir'] ?? 'asc';
                    $allUsers = $factoryEntry['allUsers'] ?? collect();
                    $totalUsers = $allUsers->count();
                    $activeUsersInFactory = $allUsers->where('is_active', 1)->count();
                    $inactiveUsersInFactory = $allUsers->where('is_active', 0)->count();
                @endphp
                <div class="bg-white border border-gray-200 border-l-4 border-l-amber-500 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition">
                    <!-- Factory Card Header -->
                    <div class="bg-gradient-to-r from-amber-50/80 via-orange-50/50 to-white px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2.5">
                                <i class="fa-solid fa-industry text-amber-600 text-lg"></i>
                                <h3 class="text-base font-semibold text-gray-800">{{ $factory->name }}</h3>
                                @if($factory->owner)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs bg-amber-100 text-amber-800 font-semibold border border-amber-200">
                                        <i class="fa-solid fa-crown text-[10px]"></i> {{ $factory->owner->name }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 mt-1 text-xs text-gray-500">
                                <span>Total Users: <b class="text-gray-800">{{ $totalUsers }}</b></span>
                                <span>•</span>
                                <span class="text-emerald-700">Active: <b class="font-semibold tabular-nums">{{ $activeUsersInFactory }}</b></span>
                                <span>•</span>
                                <span class="text-rose-700">Inactive: <b class="font-semibold tabular-nums">{{ $inactiveUsersInFactory }}</b></span>
                            </div>
                        </div>

                        <!-- Per-Factory Filters & Sorting Controls -->
                        <div class="flex items-center flex-wrap gap-2">
                            <!-- Sort Controls -->
                            <div class="flex items-center gap-1 bg-gray-100/80 p-1 rounded-lg border border-gray-200 text-xs">
                                <span class="text-gray-500 font-semibold px-1.5">Sort:</span>
                                <button wire:click="sortFactoryBy('{{ $fid }}', 'role')"
                                        class="px-2 py-1 rounded font-medium transition {{ $sortCol === 'role' ? 'bg-amber-600 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-200' }}">
                                    Role {{ $sortCol === 'role' ? ($sortDir === 'asc' ? '↑' : '↓') : '' }}
                                </button>
                                <button wire:click="sortFactoryBy('{{ $fid }}', 'name')"
                                        class="px-2 py-1 rounded font-medium transition {{ $sortCol === 'name' ? 'bg-amber-600 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-200' }}">
                                    Name {{ $sortCol === 'name' ? ($sortDir === 'asc' ? '↑' : '↓') : '' }}
                                </button>
                                <button wire:click="sortFactoryBy('{{ $fid }}', 'created')"
                                        class="px-2 py-1 rounded font-medium transition {{ $sortCol === 'created' ? 'bg-amber-600 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-200' }}">
                                    Created {{ $sortCol === 'created' ? ($sortDir === 'asc' ? '↑' : '↓') : '' }}
                                </button>
                                <button wire:click="sortFactoryBy('{{ $fid }}', 'status')"
                                        class="px-2 py-1 rounded font-medium transition {{ $sortCol === 'status' ? 'bg-amber-600 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-200' }}">
                                    Status {{ $sortCol === 'status' ? ($sortDir === 'asc' ? '↑' : '↓') : '' }}
                                </button>
                            </div>

                            <!-- Search Inputs -->
                            <input type="text"
                                wire:model.defer="factoryFilters.{{ $fid }}.name"
                                placeholder="Name..."
                                class="w-24 text-xs border border-gray-300 rounded-lg px-2.5 py-1.5 focus:ring-1 focus:ring-amber-500">
                            <input type="text"
                                wire:model.defer="factoryFilters.{{ $fid }}.email"
                                placeholder="Email..."
                                class="w-28 text-xs border border-gray-300 rounded-lg px-2.5 py-1.5 focus:ring-1 focus:ring-amber-500">
                            <select wire:model.defer="factoryFilters.{{ $fid }}.status"
                                class="text-xs border border-gray-300 rounded-lg px-2.5 py-1.5 bg-white focus:ring-1 focus:ring-amber-500">
                                <option value="">All Users</option>
                                <option value="active">Active Only</option>
                                <option value="inactive">Inactive Only</option>
                            </select>
                            <button wire:click="doFactorySearch('{{ $fid }}')" class="edit-btn btn-xs">
                                <i class="fa-solid fa-magnifying-glass"></i>Search
                            </button>
                            <button wire:click="resetFactorySearch('{{ $fid }}')" class="cancel-btn btn-xs">
                                Reset
                            </button>
                        </div>
                    </div>

                    <!-- Role Groups inside Factory -->
                    @if($groups->isEmpty())
                        <div class="px-5 py-8 text-center text-sm text-gray-500 italic">No users found matching current filters.</div>
                    @else
                        @foreach(['Owner', 'Admin', 'Supervisor', 'Staff', 'Superadmin'] as $roleName)
                            @if($groups->has($roleName))
                                @php
                                    $roleBorderColor = match($roleName) {
                                        'Owner' => 'border-l-4 border-l-amber-500',
                                        'Superadmin' => 'border-l-4 border-l-purple-500',
                                        'Admin' => 'border-l-4 border-l-blue-500',
                                        'Supervisor' => 'border-l-4 border-l-indigo-500',
                                        default => 'border-l-4 border-l-gray-400',
                                    };
                                @endphp
                                <div class="border-b border-gray-100 last:border-b-0">
                                    <!-- Role Group Section Subheader -->
                                    <div class="bg-gray-50/90 px-6 py-2 flex items-center justify-between border-b border-gray-100 {{ $roleBorderColor }}">
                                        <div class="flex items-center gap-2">
                                            @if($roleName === 'Owner')
                                                <i class="fa-solid fa-crown text-amber-500 text-xs"></i>
                                            @elseif($roleName === 'Superadmin')
                                                <i class="fa-solid fa-shield-halved text-purple-500 text-xs"></i>
                                            @elseif($roleName === 'Admin')
                                                <i class="fa-solid fa-user-gear text-blue-500 text-xs"></i>
                                            @elseif($roleName === 'Supervisor')
                                                <i class="fa-solid fa-user-tie text-indigo-500 text-xs"></i>
                                            @else
                                                <i class="fa-solid fa-user text-gray-400 text-xs"></i>
                                            @endif
                                            <span class="caps-label">{{ $roleName }}</span>
                                            <span class="text-xs tabular-nums text-gray-500 font-normal">({{ $groups[$roleName]->count() }})</span>
                                        </div>
                                    </div>

                                    <!-- Table with Proper Column Headers & Alignment -->
                                    <table class="min-w-full table-fixed divide-y divide-gray-100">
                                        <thead class="bg-gray-100/50 caps-label">
                                            <tr>
                                                <th class="w-1/4 px-6 py-2 text-left">NAME</th>
                                                <th class="w-1/4 px-4 py-2 text-left">EMAIL</th>
                                                <th class="w-1/6 px-4 py-2 text-left">MOBILE</th>
                                                <th class="w-1/6 px-4 py-2 text-left">CREATED</th>
                                                <th class="w-1/6 px-4 py-2 text-left">STATUS</th>
                                                <th class="w-1/6 px-6 py-2 text-right">ACTIONS</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            @foreach($groups[$roleName] as $user)
                                                <tr class="text-sm text-gray-900 hover:bg-amber-50/30 transition-colors {{ !$user->is_active ? 'bg-rose-50/20' : '' }}">
                                                    <!-- NAME -->
                                                    <td class="w-1/4 px-6 py-3">
                                                        <div class="flex items-center gap-2.5">
                                                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold shrink-0 {{ $user->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-600' }}">
                                                                {{ strtoupper(substr($user->name, 0, 1)) }}
                                                            </div>
                                                            <div class="truncate">
                                                                <span class="{{ !$user->is_active ? 'text-gray-600 line-through decoration-gray-400' : 'text-gray-900' }}">
                                                                    {{ $user->name }}
                                                                </span>
                                                                @if($user->is_superadmin && $roleName !== 'Owner')
                                                                    <i class="fas fa-crown text-amber-500 ml-1 text-xs" title="Superadmin"></i>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>

                                                    <!-- EMAIL (Increased font size & high contrast) -->
                                                    <td class="w-1/4 px-4 py-3 text-sm text-gray-900 truncate">
                                                        {{ $user->email }}
                                                    </td>

                                                    <!-- MOBILE -->
                                                    <td class="w-1/6 px-4 py-3 text-sm tabular-nums text-gray-900">
                                                        {{ $user->mobile ?? '-' }}
                                                    </td>

                                                    <!-- CREATED -->
                                                    <td class="w-1/6 px-4 py-3 text-sm tabular-nums text-gray-900">
                                                        {{ $user->created_at ? $user->created_at->format('d M Y') : '-' }}
                                                    </td>

                                                    <!-- STATUS -->
                                                    <td class="w-1/6 px-4 py-3">
                                                        @if($user->is_active)
                                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span> Active
                                                            </span>
                                                        @else
                                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full bg-rose-100 text-rose-800 border border-rose-200">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span> Inactive
                                                            </span>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-2 text-right">
                                                        <div class="flex items-center justify-end gap-2">
                                                            <button wire:click="edit({{ $user->id }})" class="edit-btn btn-xs">
                                                                <i class="fa-regular fa-pen-to-square"></i>Edit
                                                            </button>
                                                            <x-impersonate-button :user="$user" />
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        @endforeach
                    @endif
                </div>
            @empty
                <div class="text-center py-16 bg-white border border-gray-200 rounded-2xl shadow-sm">
                    <i class="fa-solid fa-industry text-5xl mb-3 text-gray-300 block"></i>
                    <p class="text-base font-semibold text-gray-600">No factories found.</p>
                </div>
            @endforelse
        </div>

    @else
        <!-- Users Table (Normal View) -->
        <div class="px-4 pb-6">
            <div class="bg-white border border-gray-200 border-l-4 border-l-blue-500 rounded-xl overflow-hidden shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50/80">
                        <tr class="caps-label">
                            @php
                                $columns = [
                                    ['col' => 'name',       'label' => 'Name'],
                                    ['col' => 'email',      'label' => 'Email'],
                                    ['col' => '',           'label' => 'Mobile'],
                                    ['col' => '',           'label' => 'Status'],
                                    ['col' => '',           'label' => 'Teams & Roles'],
                                    ['col' => '',           'label' => 'Actions'],
                                ];
                            @endphp
                            @foreach($columns as $item)
                                @if($item['col'])
                                    <th class="px-6 py-3 text-left cursor-pointer hover:bg-gray-100 transition"
                                        wire:click="sortBy('{{ $item['col'] }}')">
                                        <div class="flex items-center gap-1">
                                            {{ $item['label'] }}
                                            <span class="text-xs {{ $sortCol === $item['col'] ? 'text-[#83211b]' : 'text-gray-400' }}">
                                                @if($sortCol === $item['col'])
                                                    {{ $sortDir === 'asc' ? '▲' : '▼' }}
                                                @else
                                                    ⇅
                                                @endif
                                            </span>
                                        </div>
                                    </th>
                                @else
                                    <th class="px-6 py-3 text-left">{{ $item['label'] }}</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse($users as $user)
                            <tr class="text-sm text-gray-900 hover:bg-gray-50/70 transition-colors {{ !$user->is_active ? 'bg-rose-50/10' : '' }}">
                                <td class="px-6 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold {{ $user->is_active ? 'bg-blue-100 text-blue-800' : 'bg-gray-200 text-gray-600' }}">
                                            {{ strtoupper(substr($user->name, 0, 1)) }}
                                        </div>
                                        <div>
                                            <span class="{{ !$user->is_active ? 'text-gray-600 line-through decoration-gray-400' : 'text-gray-900' }}">
                                                {{ $user->name }}
                                            </span>
                                            @if($user->is_superadmin)
                                                <i class="fas fa-crown text-amber-500 ml-1 text-xs" title="Superadmin"></i>
                                            @endif
                                            @if($user->teams->contains(fn($t) => $t->pivot->role === 'owner'))
                                                <i class="fa-solid fa-industry text-amber-600 ml-1 text-xs" title="Owner"></i>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3.5 text-sm text-gray-900">{{ $user->email }}</td>
                                <td class="px-6 py-3.5 text-sm tabular-nums text-gray-900">{{ $user->mobile ?? '-' }}</td>
                                <td class="px-6 py-3.5">
                                    <span class="status-badge {{ $user->is_active ? 'status-on' : 'status-off' }}">
                                        {{ $user->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3.5">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($user->teams as $team)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs bg-gray-100 text-gray-700 border border-gray-200">
                                                <span class="font-medium">{{ $team->name }}:</span>
                                                <span class="{{ $team->pivot->role === 'owner' ? 'text-amber-700' : 'text-gray-600' }}">{{ ucfirst($team->pivot->role) }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button wire:click="edit({{ $user->id }})" class="edit-btn btn-xs">
                                            <i class="fa-regular fa-pen-to-square"></i>Edit
                                        </button>
                                        <x-impersonate-button :user="$user" />
                                        @if ($user->isDeletableOrphan())
                                            <button wire:click="confirmDeletion({{ $user->id }})" class="delete-btn btn-xs">
                                                <i class="fa-regular fa-trash-can"></i>Delete
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                    No users found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="mt-4">
                <x-pagination :items="$users" perPageBinding="perPage" />
            </div>
        </div>
    @endif

    <!-- Delete Confirmation -->
    @if ($showDeleteConfirmationDialog)
        <div class="fixed z-50 inset-0 overflow-y-auto bg-black/60">
            <div class="flex items-center justify-center min-h-screen">
                <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-md">
                    <h2 class="text-md font-semibold mb-2">Confirm Deletion</h2>
                    <p class="text-sm">This user has no factory and no records. Permanently delete the account?</p>
                    <div class="mt-4 flex justify-end gap-4">
                        <button class="delete-btn" wire:click="deleteUser">Confirm</button>
                        <button class="cancel-btn" wire:click="closeDeleteModal">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Edit Modal -->
    @if ($showModal)
        <div x-data="{ open: @entangle('showModal') }">
            <div x-show="open" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-xs">
                <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-md">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100 flex items-center gap-2">
                        <i class="fa-solid fa-user-pen text-blue-600"></i> Edit User
                    </h2>

                    <form wire:submit.prevent="update">
                        <div class="mb-3.5">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Name</label>
                            <input type="text" wire:model="name" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-1 focus:ring-blue-500">
                            @error('name') <span class="text-red-600 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3.5">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Email</label>
                            <input type="email" wire:model="email" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-1 focus:ring-blue-500">
                            @error('email') <span class="text-red-600 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3.5">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Mobile</label>
                            <input type="text" wire:model="mobile" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-1 focus:ring-blue-500">
                            @error('mobile') <span class="text-red-600 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3.5">
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Status</label>
                            <select wire:model.defer="status" 
                                    id="status" name="status" 
                                    class="w-full border border-gray-300 rounded-lg p-2 text-sm bg-white focus:ring-1 focus:ring-blue-500">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                            @error('status') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3.5">
                            <label for="newPassword" class="block text-xs font-semibold text-gray-700 mb-1">New Password (Optional)</label>
                            <div class="relative">
                                <input type="password" id="newPassword" wire:model="password" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-1 focus:ring-blue-500">
                                <button type="button" onclick="togglePasswordVisibility('newPassword', this)" class="absolute inset-y-0 right-0 px-3 text-gray-400 hover:text-gray-600">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                </button>
                            </div>
                            @error('password')
                                <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="mb-5">
                            <label for="confirmPassword" class="block text-xs font-semibold text-gray-700 mb-1">Confirm Password</label>
                            <div class="relative">
                                <input type="password" id="confirmPassword" wire:model="password_confirmation" class="w-full border border-gray-300 rounded-lg p-2 text-sm focus:ring-1 focus:ring-blue-500">
                                <button type="button" onclick="togglePasswordVisibility('confirmPassword', this)" class="absolute inset-y-0 right-0 px-3 text-gray-400 hover:text-gray-600">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                </button>
                            </div>
                            @error('password_confirmation')
                                <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
                            <button type="button" wire:click="closeForm" class="cancel-btn">Cancel</button>
                            <button type="submit" class="save-btn">Save Changes</button>
                        </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <script>
        function togglePasswordVisibility(fieldId, button) {
            const input = document.getElementById(fieldId);
            const icon = button.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</div>
