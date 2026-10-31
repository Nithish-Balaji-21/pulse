<!-- Navigation Links -->
<div id="coiiot-sidebar" x-data="{ reduce_container: JSON.parse(localStorage.getItem('coiiot_sidebar_collapsed') ?? 'false'), ready: false, subMenus: null }"
x-init="$watch('reduce_container', value => { localStorage.setItem('coiiot_sidebar_collapsed', JSON.stringify(value)); document.documentElement.classList.toggle('sidebar-collapsed', value); })"
class="h-[calc(100vh-50px)] w-52 sticky top-[50px] bottom-[0px] border-b border-r border-black/10 flex flex-col"
style="background-color: {{ config('colors.background.sidebar_bg') }};" :class="[reduce_container ? 'w-[68px]' : '', ready ? 'transition-all duration-500 ease-in-out' : '']">

    <div class="flex flex-col ps-2 pe-1 pt-3 pb-1 w-full overflow-y-auto overflow-x-hidden space-y-1 custom-scroll">

        @if($currentTeam?->has_pith_module)
            <x-menu-item :href="route('dashboard.pith', ['team' => $currentTeam?->id])"
                :active="request()->routeIs('dashboard.pith')"
                icon="dashboard" :label="__('Dashboard')" short="Dashboard" />
        @endif

        @if ($currentTeam?->has_fibre_module)
            <x-menu-item :href="route('dashboard.fibre', ['team' => $currentTeam?->id])"
                :active="request()->routeIs('dashboard.fibre')"
                icon="dashboard" :label="__('Dashboard')" short="Dashboard" />
        @endif

        @if ($currentTeam?->has_pith_module)
            <!-- IoT Insights: Pith -->
            <x-menu-item :href="route('iot-pith-loads', ['team' => $currentTeam?->id])"
                :active="request()->routeIs(['iot-pith-loads', 'iot-pith-date', 'iot-pith-blocks', 'iot-fibre-minibales', 'iot-water-consume'])"
                icon="iot-insights" :label="__('IoT Insights')" short="IoT" />
        @endif

        @if ($currentTeam?->has_fibre_module)
            <!-- IoT Insights: Fibre -->
            <x-menu-item :href="route('iot-fibre-bales', ['team' => $currentTeam?->id])"
                :active="request()->routeIs(['iot-fibre-bales', 'iot-fibre-date', 'iot-fibre-weight'])"
                icon="iot-insights" :label="__('IoT Insights')" short="IoT" />
        @endif

        @if ($currentTeam?->has_pith_module)
            <!-- Production group -->
            <p x-show="!reduce_container" class="hidden sm:block px-2 pt-3 pb-0.5 caps-label text-[11px] text-[#8a7f78] text-nowrap">{{ __('Production') }}</p>

            <!-- Pith Production -->
            @can('view-pith-production')
                <x-menu-item :href="route('pith.index', ['team' => $currentTeam?->id])"
                    :active="request()->routeIs(['pith.index', 'pith.create', 'pith.edit', 'pith.report'])"
                    icon="pith-production" :label="__('Pith Production')" short="Pith" />
            @endcan

            <!-- Coir Fibre -->
            @if ($currentTeam?->has_pith_module_bales)
                @can('view-fibre-bales')
                <x-menu-item :href="route('fibre.index', ['team' => $currentTeam?->id])"
                    :active="request()->routeIs(['fibre.index', 'fibre.create', 'fibre.edit', 'fibre.report'])"
                    icon="fibre-bales" :label="__('Fibre Bales')" short="Bales" />
                @endcan
            @endif

            <!-- Pith Blocks -->
            @if ($currentTeam?->has_pith_module_blocks)
                @can('view-pith-blocks')
                <x-menu-item :href="route('pith-blocks.index', ['team' => $currentTeam?->id])"
                    :active="request()->routeIs(['pith-blocks.index', 'pith-blocks.create', 'pith-blocks.edit'])"
                    icon="pith-blocks" :label="__('Pith Blocks')" short="Blocks" />
                @endcan
            @endif

            <!-- Inventory group -->
            <p x-show="!reduce_container" class="hidden sm:block px-2 pt-3 pb-0.5 caps-label text-[11px] text-[#8a7f78] text-nowrap">{{ __('Inventory') }}</p>

            <!-- Pith Inventory -->
            @can('view-pith-inventory')
                <x-menu-item :href="route('pith.inventory', ['team' => $currentTeam?->id])"
                    :active="request()->routeIs(['pith.inventory', 'pith.taken', 'pith.inventory.history'])"
                    icon="pith-inventory" :label="__('Pith Inventory')" short="Pith Inv." />
            @endcan

            <!-- Husk Inventory -->
            @can('view-husk-inventory')
                <x-menu-item :href="route('husk.inventory', ['team' => $currentTeam?->id])"
                    :active="request()->routeIs('husk.inventory')"
                    icon="husk-inventory" :label="__('Husk Inventory')" short="Husk Inv." />
            @endcan
        @endif

        <!-- Growbags -->
        @if ($currentTeam?->has_growbags_module)
            <x-menu-item :href="route('dashboard.growbags', ['team' => $currentTeam?->id])"
                :active="request()->routeIs('dashboard.growbags')"
                icon="dashboard" :label="__('Dashboard')" short="Dashboard" />

            {{-- iot insight --}}
            <x-menu-item :href="route('iot-growbags', ['team' => $currentTeam?->id])"
                :active="request()->routeIs('iot-growbags')"
                icon="iot-insights" :label="__('IoT Insights')" short="IoT" />

            @can('view-growbag-production')
                <x-menu-item :href="route('growbag.production', ['team' => $currentTeam?->id])"
                    :active="request()->routeIs('growbag.production')"
                    icon="growbags" :label="__('Production')" short="Growbags" />
            @endcan
        @endif

        <!-- Assets -->
        {{-- <div class="hidden sm:-my-px sm:flex flex-col relative sm:mb-0.5 text-nowrap">
            <x-nav-link href="{{ route('assets.index', ['team' => $currentTeam?->id]) }}" :active="request()->routeIs(['assets.index','assets.view'])"
                class="flex items-center w-full !px-2 py-2 rounded-lg hover:bg-[#ededf7] transition-all duration-300 cursor-pointer {{ request()->routeIs(['assets.index', 'assets.view']) ? 'bg-amber-800 text-white hover:bg-amber-800' : '' }}">
                <div class="w-6 text-start shrink-0">
                    <i class="fa-solid fa-screwdriver-wrench fa-fw text-sm"></i>
                </div>
                <div class="flex-1 flex items-center justify-between">
                    <span class="">{{ __('Assets') }}</span>
                    <i class=""></i>
                </div>
            </x-nav-link>
        </div> --}}

        @if ($currentTeam?->has_pith_module)
            <!-- Operations group -->
            <p x-show="!reduce_container" class="hidden sm:block px-2 pt-3 pb-0.5 caps-label text-[11px] text-[#8a7f78] text-nowrap">{{ __('Operations') }}</p>
        @endif

        <!-- Staff Attendance (module on + attendance:view) -->
        @if($currentTeam?->has_attendance_module)
            @can('view-attendance')
                <x-menu-item :href="route('attendance.index', ['team' => $currentTeam?->id])"
                    :active="request()->routeIs(['attendance.index'])"
                    icon="labour" :label="__('Staff Attendance')" short="Staff" />
            @endcan
        @endif

        <!-- Weather (no module flag, but still team-scoped: without a team the
             route has no {team} and rendering the menu would throw) -->
        @if($currentTeam)
            <x-menu-item :href="route('weather.index', ['team' => $currentTeam->id])"
                :active="request()->routeIs('weather.index')"
                icon="weather" :label="__('Weather')" short="Weather" />
        @endif

        <!-- Reports -->
        @if($currentTeam?->has_reports_module && (
            auth()->user()->can('pith_inventory.inventory_report')
            || auth()->user()->can('husk_inventory.inventory_report')
            || ($currentTeam?->has_pith_module_bales && auth()->user()->can('view-bale-inventory'))
            || ($currentTeam?->has_pith_module_blocks && auth()->user()->can('view-block-inventory'))
            || auth()->user()->can('attendance.salary_report')
        ))
            <x-menu-item :href="route('reports.hub', ['team' => $currentTeam->id])"
                :active="request()->routeIs(['reports.*', 'report.index'])"
                icon="reports" :label="__('Reports')" short="Reports" />
        @endif
    </div>
    <div class="relative flex-1 border-r border-[#4b443f]/10" style="background-color: {{ config('colors.background.sidebar_bg') }};">
        <button @click="ready = true; reduce_container = !reduce_container" class="absolute bottom-0 right-0 w-8 h-8 rounded-tl-lg bg-[#4b443f]/8 hover:bg-[#4b443f]/15 z-50 flex items-center justify-center text-[#4b443f]/70 hover:text-[#4b443f]">
            {{-- Collapse (shown when expanded) --}}
            <svg x-show="!reduce_container" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor" class="w-4 h-4" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M157.3 413.2c-6.6 0-13.1-2.5-18.2-7.5L7.5 274c-10-10-10-26.3 0-36.3L139.2 106c10-10 26.3-10 36.3 0s10 26.3 0 36.3L62 255.9l113.5 113.5c10 10 10 26.3 0 36.3-5 5-11.6 7.5-18.2 7.5"></path><path fill-rule="evenodd" clip-rule="evenodd" d="M290.1 285H73.5c-14.2 0-25.7-11.5-25.7-25.7s11.5-25.7 25.7-25.7h216.7c14.2 0 25.7 11.5 25.7 25.7-.1 14.2-11.6 25.7-25.8 25.7m195.2 0h-94.4c-14.2 0-25.7-11.5-25.7-25.7s11.5-25.7 25.7-25.7h94.4c14.2 0 25.7 11.5 25.7 25.7S499.5 285 485.3 285m0 125.1H342c-14.2 0-25.7-11.5-25.7-25.7s11.5-25.7 25.7-25.7h143.3c14.2 0 25.7 11.5 25.7 25.7s-11.5 25.7-25.7 25.7m0-250.1H342c-14.2 0-25.7-11.5-25.7-25.7s11.5-25.7 25.7-25.7h143.3c14.2 0 25.7 11.5 25.7 25.7S499.5 160 485.3 160"></path></svg>
            {{-- Expand (shown when collapsed) --}}
            <svg x-show="reduce_container" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor" class="w-4 h-4" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M353.7 98.5c6.6 0 13.1 2.5 18.2 7.5l131.7 131.7c10 10 10 26.3 0 36.3L371.8 405.7c-10 10-26.3 10-36.3 0s-10-26.3 0-36.3L449 255.9 335.5 142.4c-10-10-10-26.3 0-36.3 5-5.1 11.6-7.6 18.2-7.6"></path><path fill-rule="evenodd" clip-rule="evenodd" d="M220.9 226.8h216.7c14.2 0 25.7 11.5 25.7 25.7s-11.5 25.7-25.7 25.7H220.9c-14.2 0-25.7-11.5-25.7-25.7s11.5-25.7 25.7-25.7m-195.2 0h94.4c14.2 0 25.7 11.5 25.7 25.7s-11.5 25.7-25.7 25.7H25.7C11.5 278.1 0 266.6 0 252.5s11.5-25.7 25.7-25.7m0-125.1H169c14.2 0 25.7 11.5 25.7 25.7S183.2 153 169 153H25.7C11.5 153 0 141.5 0 127.3s11.5-25.6 25.7-25.6m0 250H169c14.2 0 25.7 11.5 25.7 25.7s-11.5 25.7-25.7 25.7H25.7C11.5 403.1 0 391.6 0 377.4s11.5-25.7 25.7-25.7"></path></svg>
        </button>
    </div>
</div>
