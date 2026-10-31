@php
    $navUser = Auth::user();
    $navTeams = $navUser->allTeams();
    $navIsSuperadmin = $navUser->isSuperadmin();

    $navModules = collect([
        'pith' => $currentTeam?->has_pith_module,
        'fibre' => $currentTeam?->has_fibre_module,
        'growbag' => $currentTeam?->has_growbags_module,
    ])->filter()->keys()->implode(' · ');

    $navNameWords = preg_split('/\s+/', trim((string) $navUser->name), -1, PREG_SPLIT_NO_EMPTY);
    $navUserInitials = mb_strtoupper(count($navNameWords) >= 2
        ? mb_substr($navNameWords[0], 0, 1).mb_substr(end($navNameWords), 0, 1)
        : mb_substr($navNameWords[0] ?? '?', 0, 2));

    $navRoleKey = $currentTeam ? $navUser->getCurrentTeamRole() : null;
    $navRoleLabel = $navIsSuperadmin
        ? 'Superadmin'
        : ($navRoleKey
            ? (\App\Support\Pulse\TeamRolePermissions::roleForTeam($currentTeam->id, $navRoleKey)['name'] ?? Str::headline($navRoleKey))
            : null);
@endphp

<nav x-data="{
        menu: null,
        mobileOpen: false,
        toggle(name) { this.menu = this.menu === name ? null : name },
        close() { this.menu = null },
     }"
     @keydown.escape.window="close()"
     @keydown.window.meta.k.prevent="toggle('factory')"
     @keydown.window.ctrl.k.prevent="toggle('factory')"
     class="sticky top-0 z-50 bg-white">
    <div class="flex w-full items-center justify-between ps-3 pe-2 h-[50px] border-b border-[#ebe5df]" style="background-color: {{ config('colors.background.sidebar_bg') }};">

        <div class="flex items-center">
            <a href="{{ $currentTeam?->has_pith_module ? route('dashboard.pith', ['team' => $currentTeam?->id]) : ($currentTeam?->has_fibre_module ? route('dashboard.fibre', ['team' => $currentTeam?->id]) : ($currentTeam?->has_growbags_module ? route('dashboard.growbags', ['team' => $currentTeam?->id]) : route('dashboard.redirect'))) }}"
                class="flex items-center gap-1.5 shrink-0">
                <img src="{{ asset('images/logo-color.png') }}" alt="CoiioT" class="h-6 w-auto">
            </a>

            @if (isset($showExpiryWarning) && $showExpiryWarning)
                <div class="flex items-center text-amber-700 text-sm ms-4 gap-1.5">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    License expires on {{ \Carbon\Carbon::parse($expiryDate)->format('d/m/Y') }}. Please contact us to renew.
                </div>
            @endif
        </div>

        <div class="hidden sm:flex sm:items-center gap-1.5 text-[#1f1b18]">

            {{-- Factory switcher --}}
            @if ($currentTeam)
                @php $navCanSwitch = $navTeams->count() > 1; @endphp
                <div class="relative" @click.outside="menu === 'factory' && close()">
                    <button type="button"
                            @if ($navCanSwitch) @click="toggle('factory')" @else disabled @endif
                            :class="menu === 'factory' ? 'bg-[#f7f3f0] border-[#d9cfc6]' : 'bg-white border-[#ebe5df]'"
                            class="flex items-center gap-2.5 h-10 ps-3 pe-2.5 border rounded-[10px] text-left {{ $navCanSwitch ? 'hover:bg-[#f7f3f0]' : 'cursor-default' }} focus:outline-none focus-visible:ring-2 focus-visible:ring-[#83211b]/30">
                        <span class="grid leading-[1.15]">
                            <span class="text-xs text-[#8a817a]">Factory</span>
                            <span class="text-sm font-semibold max-w-[220px] truncate">{{ $currentTeam->display_name }}</span>
                        </span>
                        @if ($navModules !== '')
                            <span class="text-xs px-[7px] py-0.5 rounded-full bg-[#f3eee9] text-[#6b635c]">{{ $navModules }}</span>
                        @endif
                        @if ($navCanSwitch)
                            <i class="fa-solid fa-sort text-xs text-[#8a817a]"></i>
                        @endif
                    </button>

                    @if ($navCanSwitch)
                        <div x-show="menu === 'factory'" x-cloak
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute left-0 top-[calc(100%+8px)] z-20">
                            <x-factory-switcher-panel :teams="$navTeams" :currentTeam="$currentTeam" />
                        </div>
                    @endif
                </div>

                <span class="w-px h-6 mx-1.5 bg-[#e6e0da]"></span>
            @endif

            <div class="flex items-center gap-1.5">
                <livewire:pulse.cft-calculator />
                <livewire:pulse.sump-calculator />
            </div>

            {{-- Admin: platform-wide tools, superadmins only --}}
            @if ($navIsSuperadmin && $currentTeam)
                <div class="relative" @click.outside="menu === 'admin' && close()">
                    <button type="button" @click="toggle('admin')"
                            :class="menu === 'admin' ? 'bg-[#fdf1dc]' : 'bg-[#fffaf1]'"
                            class="flex items-center gap-1.5 h-8 px-2.5 border border-[#f0d9ae] rounded-full text-[13px] font-semibold text-[#8a5207] hover:bg-[#fdf1dc] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#8a5207]/30">
                        <i class="fa-solid fa-user-shield text-xs"></i>Admin
                    </button>
                    <div x-show="menu === 'admin'" x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="absolute right-0 top-[calc(100%+8px)] w-[260px] p-1.5 bg-white border border-[#e6e0da] rounded-xl shadow-[0_12px_32px_rgba(40,20,10,0.14)] z-20">
                        <div class="px-2.5 pt-2 pb-1.5 text-xs text-[#8a5207]">Platform · all factories</div>
                        @foreach ([
                            ['fa-users', 'All users', route('allusers.index', ['team' => $currentTeam->id])],
                            ['fa-industry', 'All factories', route('allfactories.index', ['team' => $currentTeam->id])],
                            ['fa-user-secret', 'Impersonation log', route('impersonation-logs.index', ['team' => $currentTeam->id])],
                            ['fa-sliders', 'Platform settings', route('superadmin-settings.index', ['team' => $currentTeam->id])],
                            ['fa-chart-line', 'DevOps Tools', route('superadmin.monitoring', ['team' => $currentTeam->id])],
                        ] as [$icon, $label, $url])
                            <a href="{{ $url }}" class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm text-[#1f1b18] hover:bg-[#f6f2ee] hover:text-[#1f1b18] hover:no-underline">
                                <i class="fa-solid {{ $icon }} w-[18px] text-center text-[#6b635c]"></i>{{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Factory settings --}}
            @if ($currentTeam && Laravel\Jetstream\Jetstream::hasTeamFeatures() && $navUser->can('view-settings'))
                <div class="relative" @click.outside="menu === 'settings' && close()">
                    <button type="button" @click="toggle('settings')" title="Factory settings" aria-label="Factory settings"
                            :class="menu === 'settings' ? 'bg-[#f3eee9]' : 'bg-transparent'"
                            class="size-9 grid place-items-center rounded-[10px] text-[#3d3631] hover:bg-[#f3eee9] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#83211b]/30">
                        <i class="fa-solid fa-gear text-[18px]"></i>
                    </button>
                    <div x-show="menu === 'settings'" x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="absolute -right-[100px] top-[calc(100%+8px)] z-20">
                        <x-settings-panel :team="$currentTeam" />
                    </div>
                </div>
            @endif

            {{-- Notifications inbox --}}
            @if ($currentTeam)
                <livewire:notification-bell :team-id="$currentTeam->id" />
            @endif

            {{-- Account --}}
            <div class="relative ms-1" @click.outside="menu === 'account' && close()">
                <button type="button" @click="toggle('account')" title="Account" aria-label="Account"
                        :class="menu === 'account' ? 'border-[#83211b]' : 'border-transparent'"
                        class="size-10 grid place-items-center p-0 border-2 rounded-full bg-white focus:outline-none focus-visible:border-[#83211b]/50">
                    <span class="size-8 rounded-full bg-[#2f3a55] text-white grid place-items-center text-xs font-semibold">{{ $navUserInitials }}</span>
                </button>
                <div x-show="menu === 'account'" x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="absolute right-0 top-[calc(100%+8px)] w-[280px] bg-white border border-[#e6e0da] rounded-xl shadow-[0_12px_32px_rgba(40,20,10,0.14)] overflow-hidden z-20">
                    <div class="flex items-center gap-3 p-3.5 border-b border-[#efe9e3]">
                        <span class="size-10 flex-none rounded-full bg-[#2f3a55] text-white grid place-items-center text-sm font-semibold">{{ $navUserInitials }}</span>
                        <span class="grid gap-0.5 min-w-0">
                            <span class="text-sm font-semibold truncate">{{ $navUser->name }}</span>
                            <span class="text-xs text-[#8a817a] truncate">{{ $navUser->email }}</span>
                            @if ($navRoleLabel)
                                <span class="text-xs font-semibold {{ $navIsSuperadmin ? 'text-[#8a5207]' : 'text-[#6b635c]' }}">{{ $navRoleLabel }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="p-1.5">
                        <a href="{{ route('profile.show') }}" class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm text-[#1f1b18] hover:bg-[#f6f2ee] hover:text-[#1f1b18] hover:no-underline">
                            <i class="fa-regular fa-user w-[18px] text-center text-[#6b635c]"></i>Profile
                        </a>
                        @if ($currentTeam)
                            @can('view-notification-preferences')
                                <a href="{{ route('notification-preferences.index', ['team' => $currentTeam->id]) }}" class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm text-[#1f1b18] hover:bg-[#f6f2ee] hover:text-[#1f1b18] hover:no-underline">
                                    <i class="fa-regular fa-bell w-[18px] text-center text-[#6b635c]"></i>Notification preferences
                                </a>
                            @endcan
                        @endif
                        @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                            <a href="{{ route('api-tokens.index') }}" class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm text-[#1f1b18] hover:bg-[#f6f2ee] hover:text-[#1f1b18] hover:no-underline">
                                <i class="fa-solid fa-key w-[18px] text-center text-[#6b635c]"></i>API tokens
                            </a>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('logout') }}" x-data class="p-1.5 border-t border-[#efe9e3]">
                        @csrf
                        <a href="{{ route('logout') }}" @click.prevent="$root.submit()"
                           class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm text-[#b42318] hover:bg-[#fdf0ee] hover:text-[#b42318] hover:no-underline">
                            <i class="fa-solid fa-right-from-bracket w-[18px] text-center"></i>Log out
                        </a>
                    </form>
                </div>
            </div>
        </div>

        {{-- Hamburger --}}
        <div class="-me-2 flex items-center gap-1.5 sm:hidden">
            <livewire:pulse.cft-calculator />
            <livewire:pulse.sump-calculator />
            <button @click="mobileOpen = ! mobileOpen"
                class="inline-flex items-center justify-center p-2 text-[#4b443f] hover:text-[#83211b] hover:bg-[#4b443f]/8 focus:outline-hidden focus:bg-[#4b443f]/8 focus:text-[#83211b] transition duration-150 ease-in-out">
                <svg class="size-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path :class="{ 'hidden': mobileOpen, 'inline-flex': !mobileOpen }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path :class="{ 'hidden': !mobileOpen, 'inline-flex': mobileOpen }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Responsive Menu --}}
    <div :class="{ 'block': mobileOpen, 'hidden': !mobileOpen }" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link href="{{ $currentTeam ? route('dashboard', ['team' => $currentTeam->id]) : route('dashboard.redirect') }}" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
        </div>
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="flex items-center px-4">
                <div>
                    <div class="font-medium text-sm text-gray-800">{{ $navUser->name }}</div>
                    <div class="font-medium text-sm text-gray-500">{{ $navUser->email }}</div>
                </div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link href="{{ route('profile.show') }}" :active="request()->routeIs('profile.show')">{{ __('Profile') }}</x-responsive-nav-link>
                @if (Auth::user()->is_superadmin && $currentTeam)
                    <x-responsive-nav-link href="{{ route('superadmin.monitoring', ['team' => $currentTeam->id]) }}" :active="request()->routeIs('superadmin.monitoring')">{{ __('DevOps Tools') }}</x-responsive-nav-link>
                @endif
                @if ($currentTeam)
                    @can('view-notification-preferences')
                        <x-responsive-nav-link href="{{ route('notification-preferences.index', ['team' => $currentTeam->id]) }}" :active="request()->routeIs('notification-preferences.index')">{{ __('Notification preferences') }}</x-responsive-nav-link>
                    @endcan
                @endif
                <form method="POST" action="{{ route('logout') }}" x-data>
                    @csrf
                    <x-responsive-nav-link href="{{ route('logout') }}" @click.prevent="$root.submit();">{{ __('Log Out') }}</x-responsive-nav-link>
                </form>
                @if (Laravel\Jetstream\Jetstream::hasTeamFeatures())
                    <div class="border-t border-gray-200"></div>
                    @if ($currentTeam)
                        <x-responsive-nav-link href="{{ route('teams.show', $currentTeam->id) }}" :active="request()->routeIs('teams.show')">{{ __('Administration') }}</x-responsive-nav-link>
                    @endif
                    @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                        <x-responsive-nav-link href="{{ route('teams.create') }}" :active="request()->routeIs('teams.create')">{{ __('Create New Factory') }}</x-responsive-nav-link>
                    @endcan
                    @if ($navTeams->count() > 1)
                        <div class="border-t border-gray-200"></div>
                        <div class="block px-3 py-1.5 text-xs text-gray-400">{{ __('Switch Companies') }}</div>
                        @foreach ($navTeams as $team)
                            <x-switchable-team :team="$team" component="responsive-nav-link" />
                        @endforeach
                    @endif
                @endif
            </div>
        </div>
    </div>
</nav>
