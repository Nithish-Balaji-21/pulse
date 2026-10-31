<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=1366">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'CoiioT') }}</title>

        <!-- Fonts -->
        <link rel="preload" href="{{ asset('fonts/figtree/figtree-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
        <link rel="stylesheet" href="@assetVersion('css/fonts.css')">

        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

        <link rel="icon" href="{{ asset('images/logo.png') }}" type="image/png">
        
        <link rel="stylesheet" href="@assetVersion('css/factory-wizard.css')">

        <!-- Tailwind css -->
        @production
            <link href="@assetVersion('css/output.css')" rel="stylesheet">
        @else
            <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
            {{-- Tailwind v3 compatibility + palette tweaks; keep in sync with resources/css/app.css --}}
            <style type="text/tailwindcss">
                @layer base {
                    *,
                    ::after,
                    ::before,
                    ::backdrop,
                    ::file-selector-button {
                        border-color: var(--color-gray-200, currentColor);
                    }

                    button:not(:disabled),
                    [role="button"]:not(:disabled) {
                        cursor: pointer;
                    }
                }

                @theme {
                    --color-gray-400: oklch(66% 0.022 261.325);
                    --color-gray-500: oklch(50% 0.027 264.364);
                    --color-gray-600: oklch(40% 0.03 256.802);
                    --color-gray-700: oklch(33% 0.034 259.733);
                    --default-ring-color: rgb(59 130 246 / 0.5);
                    --font-sans: Figtree, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', 'Noto Sans', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';
                }
            </style>
        @endproduction

        {{-- Apply the persisted sidebar state before first paint so the menu doesn't jump on navigation --}}
        <script>
            try {
                if (JSON.parse(localStorage.getItem('coiiot_sidebar_collapsed') ?? 'false')) {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (e) {}
        </script>
        <style>
            [x-cloak] { display: none; }
            /* Prevent the rubber-band overscroll flash (white gap at top/bottom) when scrolling past the page bounds */
            html, body { overscroll-behavior-y: none; }
            /* Collapsed sidebar layout, driven by the pre-paint html class so pages never shift after load */
            html.sidebar-collapsed #coiiot-sidebar { width: 68px; }
            html.sidebar-collapsed #coiiot-sidebar [x-show="!reduce_container"] { display: none !important; }
            html.sidebar-collapsed #coiiot-sidebar .custom-scroll { padding-inline-start: 0.25rem; }
            html.sidebar-collapsed #coiiot-sidebar .menu-link { flex-direction: column; align-items: center; justify-content: center; gap: 0.125rem; padding-inline: 0.25rem; }
            html.sidebar-collapsed #coiiot-sidebar .menu-icon-box { width: auto; justify-content: center; }
        </style>

        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script>
            const jsDateFormat = '{{ config('app.date_format_js') }}';
        </script>

        <!-- For date picker -->
        <script type="text/javascript" src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
        <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
        <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

        <!-- Scripts -->
        <script src="@assetVersion('js/utils.js')"></script>
        <script src="@assetVersion('js/app-helpers.js')"></script>
        <script src="@assetVersion('js/toast.js')"></script>
        <script src="@assetVersion('js/export-table.js')"></script>
        
        <!-- Livewire Styles -->
        @livewireStyles
        <link rel="stylesheet" href="@assetVersion('css/standard.css')">
        @stack('head')
    </head>

    <body class="overflow-x-hidden font-sans antialiased">
        @if (app()->environment('demo'))
            <div class="w-full text-white text-sm font-semibold text-center py-2.5 px-4 shadow-md sticky top-0 z-[60] flex items-center justify-center gap-2" style="background: linear-gradient(135deg, #83211b 0%, #a7490b 50%, #ce7716 100%);">
                <i class="fa-solid fa-circle-info text-amber-200"></i>
                <span>Welcome to the Demo Environment! Feel free to explore.</span>
            </div>
        @endif

        {{-- Impersonation banner: always visible so the superadmin can never
             forget whose account they are looking at, or lose the way out. --}}
        @if (\App\Services\Impersonation::isActive())
            @php
                $impersonationWritable = \App\Services\Impersonation::canWrite();
            @endphp
            <div class="w-full text-[13px] py-2 px-5 sticky top-0 z-[70] flex flex-wrap items-center gap-x-3 gap-y-2 border-b {{ $impersonationWritable ? 'bg-[#fdecea] border-[#f3c1bb] text-[#8f1d1d]' : 'bg-[#fdf1dc] border-[#f0d9ae] text-[#7a4a05]' }}">
                <i class="fa-solid {{ $impersonationWritable ? 'fa-triangle-exclamation' : 'fa-eye' }}"></i>
                <span>
                    You're viewing as <b>{{ auth()->user()->name }}</b>.
                    {{ $impersonationWritable ? 'Changes you make are saved as them, and logged.' : 'Read only — actions are logged.' }}
                </span>
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $impersonationWritable ? 'bg-[#f8d3ce]' : 'bg-[#f7e2bb]' }}">
                    {{ $impersonationWritable ? 'Write mode' : 'Read only' }}
                </span>

                <span class="ms-auto flex items-center gap-2">
                    <form method="POST" action="{{ route('impersonate.write-mode') }}">
                        @csrf
                        <input type="hidden" name="enable" value="{{ $impersonationWritable ? 0 : 1 }}">
                        <button type="submit"
                                class="px-2.5 py-1 rounded-lg font-semibold bg-transparent hover:bg-white/60 {{ $impersonationWritable ? 'border border-[#eab0a8]' : 'border border-[#e2c48d]' }}">
                            {{ $impersonationWritable ? 'Switch to read-only' : 'Enable write mode' }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('impersonate.stop') }}">
                        @csrf
                        <button type="submit"
                                class="px-2.5 py-1 rounded-lg font-semibold bg-white hover:bg-white/80 {{ $impersonationWritable ? 'border border-[#eab0a8]' : 'border border-[#e2c48d]' }}">
                            Exit impersonation
                        </button>
                    </form>
                </span>
            </div>
        @endif

        <!-- Flash Messages for Toast -->
        @if(session('success'))
            <div data-flash-success="{{ session('success') }}" style="display:none;"></div>
        @endif
        @if(session('error'))
            <div data-flash-error="{{ session('error') }}" style="display:none;"></div>
        @endif
        @if(session('warning'))
            <div data-flash-warning="{{ session('warning') }}" style="display:none;"></div>
        @endif
        @if(session('info'))
            <div data-flash-info="{{ session('info') }}" style="display:none;"></div>
        @endif

        <div class="min-h-screen">

            <!-- navigation menu (full width) -->
            @php
                $currentTeam = Auth::user()?->activeTeam();

                // Dashboards that render white cards, so they need the tinted page canvas behind them.
                $hasDashboardCanvas = Request::routeIs(['dashboard.pith', 'dashboard.fibre', 'dashboard.growbags']);
            @endphp
            @auth
                @include('pulse::navigation-menu')
            @endauth

            <!-- Page Content -->
            <main class="flex w-full">

                <!-- Navigation Menus -->
                <div class="w-fit shrink-0 bg-[#f7f7fe]">
                    @include('pulse::coiiot-menu')
                </div>

                <div class="flex-auto min-w-0">

                     <!-- Page Heading -->
                    @if (isset($header))
                        <header id="pulse-page-header" class="flex bg-white rounded-lg place-content-end
                                @if (Request::routeIs(['dashboard.pith', 'dashboard.fibre', 'dashboard.growbags'])) sticky top-[50px] z-40 @endif"
                                @if ($hasDashboardCanvas)
                                    style="background-color: {{ config('colors.background.screen') }};"
                                @endif
                            >
                            <div class="w-full py-2 ps-2 sm:ps-3 lg:ps-4 pe-3">
                                {{ $header }}
                            </div>
                        </header>
                    @endif

                    @if ($hasDashboardCanvas)
                        <script>
                            /*
                             * The dashboards pin their period picker, so everything scrolling past goes
                             * under that header — and the first thing it swallowed was the leading chart
                             * card's title, leaving an unlabelled chart. Publish where the header ends so
                             * the cards can pin their own headings just below it.
                             *
                             * Measured rather than hardcoded: the header grows a row for the
                             * "wait until…" notice and wraps at narrow widths.
                             */
                            (function () {
                                const header = document.getElementById('pulse-page-header');

                                if (! header) {
                                    return;
                                }

                                const publish = () => document.documentElement.style.setProperty(
                                    '--pulse-header-bottom',
                                    ((parseFloat(getComputedStyle(header).top) || 0) + header.offsetHeight) + 'px'
                                );

                                publish();
                                new ResizeObserver(publish).observe(header);
                            })();
                        </script>
                    @endif

                    <!-- Other Contents -->
                    @if ($hasDashboardCanvas)
                        <div class="min-h-screen" style="background-color: {{ config('colors.background.screen') }};">
                            {{ $slot }}
                        </div>
                    @else
                        {{ $slot }}
                    @endif
                </div>
            </main>
        </div>

        @stack('modals')

        <!-- Livewire Scripts -->
        @livewireScripts

        <!-- ApexCharts JS -->
        <script src="@assetVersion('js/apexcharts.js')"></script>
        <script>
            // Set global options for all ApexCharts instances
            Apex.chart = {
                fontFamily: 'inherit'  // This ensures all charts inherit the font-family from the application
            };
            Apex.xaxis = { labels: { style: { fontSize: '12px', colors: '#6b7280' }, rotate: 0, hideOverlappingLabels: true } };
            Apex.yaxis = { labels: { style: { fontSize: '12px', colors: '#6b7280' } } };
            Apex.legend = { fontSize: '13px' };
            Apex.dataLabels = { style: { fontSize: '12px', fontWeight: 600 } };
        </script>

        <!-- Stack for additional scripts -->
        @stack('scripts')
        <script>
            window.addEventListener('refresh-navigation-menu', function () {
                var currentPath = window.location.pathname;
                // Extract team ID from current URL (works for both /teams/11 and /11/teams)
                var teamId = currentPath.match(/\/teams\/(\d+)/)?.[1]
                        ?? currentPath.match(/^\/(\d+)/)?.[1];

                if (teamId) {
                    window.location.href = '/teams/' + teamId;
                }
            });
        </script>

        @if (app()->environment('demo'))
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    function applyDemoReadOnly() {
                        // Mark form fields as readonly while allowing full navigation and searching
                        document.querySelectorAll('input:not([type="hidden"]):not([type="search"]), select, textarea').forEach(function (el) {
                            if (el.id === 'team-switch-search' || el.id === 'search' || el.classList.contains('allow-demo')) return;
                            el.setAttribute('readonly', 'readonly');
                            el.classList.add('bg-gray-50', 'cursor-not-allowed');
                        });

                        // Inject Read-Only warning banner at the top of form containers in demo environment
                        document.querySelectorAll('form').forEach(function (form) {
                            if (form.classList.contains('allow-demo') || 
                                (form.action && (form.action.includes('/logout') || form.action.includes('/impersonate'))) ||
                                form.querySelector('#team-switch-search') || 
                                form.querySelector('#search')) return;

                            const controls = form.querySelectorAll('input:not([type="hidden"]):not([type="search"]), select, textarea');
                            if (controls.length === 0) return;

                            if (!form.querySelector('.demo-readonly-form-banner')) {
                                const banner = document.createElement('div');
                                banner.className = 'demo-readonly-form-banner mb-5 p-3.5 px-4 rounded-xl border border-amber-300 bg-amber-50/90 text-amber-900 text-sm font-medium flex items-center justify-between gap-3 shadow-xs w-full';
                                banner.innerHTML = '<div class="flex items-center gap-2.5">' +
                                    '<span class="p-1.5 rounded-lg bg-amber-200/70 text-amber-800 shrink-0"><i class="fa-solid fa-lock text-sm"></i></span>' +
                                    '<span><strong class="font-semibold text-amber-950">Demo Environment Notice:</strong> This form is in <strong class="font-semibold text-amber-950">Read-Only</strong> mode. Edits and submissions are disabled.</span>' +
                                    '</div>' +
                                    '<span class="caps-label px-2 py-0.5 rounded bg-amber-200 text-amber-900 shrink-0">Read-Only</span>';
                                form.insertBefore(banner, form.firstChild);
                            }
                        });
                    }

                    applyDemoReadOnly();
                    setTimeout(applyDemoReadOnly, 500);
                    setTimeout(applyDemoReadOnly, 1500);

                    // Re-apply when dynamic elements or Livewire updates occur
                    const observer = new MutationObserver(function() {
                        applyDemoReadOnly();
                    });
                    observer.observe(document.body, { childList: true, subtree: true });

                    // Block form submissions & display demo warning toast
                    document.addEventListener('submit', function (e) {
                        const form = e.target;
                        // /impersonate is exempt alongside /logout — blocking it would
                        // strand a superadmin inside an impersonation with no way out.
                        if (form && form.action && (form.action.includes('/logout') || form.action.includes('/impersonate'))) return;
                        e.preventDefault();
                        e.stopPropagation();
                        if (window.showToast) {
                            window.showToast('Demo Environment: All forms are read-only. Modifications are disabled.', 'warning');
                        } else {
                            alert('Demo Environment: All forms are read-only. Modifications are disabled.');
                        }
                        return false;
                    }, true);

                    // Intercept save/delete actions to show warning while keeping links & navigation working
                    document.addEventListener('click', function (e) {
                        const btn = e.target.closest('button[type="submit"], input[type="submit"], button.btn-save, button.btn-delete, [wire\\:click*="delete"], [wire\\:click*="save"], [wire\\:click*="store"], [wire\\:click*="update"]');
                        const isImpersonationControl = btn && btn.form && btn.form.action.includes('/impersonate');
                        if (btn && btn.id !== 'login-btn' && ! isImpersonationControl) {
                            e.preventDefault();
                            e.stopPropagation();
                            if (window.showToast) {
                                window.showToast('Demo Environment: All forms are read-only. Modifications are disabled.', 'warning');
                            } else {
                                alert('Demo Environment: All forms are read-only. Modifications are disabled.');
                            }
                        }
                    }, true);
                });
            </script>
        @endif
    </body>
</html>
