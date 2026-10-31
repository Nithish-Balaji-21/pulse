<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'CoiioT') }}</title>

        <!-- Fonts -->
        <link rel="preload" href="{{ asset('fonts/figtree/figtree-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
        <link rel="stylesheet" href="@assetVersion('css/fonts.css')">

        <link rel="icon" href="{{ asset('images/logo.png') }}" type="image/png">

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

        <!-- Styles -->
        @livewireStyles

        <style>
            /* Prevent the rubber-band overscroll flash (white gap at top/bottom) when scrolling past the page bounds */
            html, body { overscroll-behavior-y: none; }
        </style>
    </head>
    <body>
        @if (app()->environment('demo'))
            <div class="w-full bg-gradient-to-r from-amber-600 via-orange-600 to-amber-700 text-white text-sm font-semibold text-center py-2.5 px-4 shadow-md relative z-[60] flex items-center justify-center gap-2">
                <i class="fa-solid fa-circle-info text-amber-200"></i>
                <span>Welcome to the Demo Environment! Feel free to explore.</span>
            </div>
        @endif
        <div class="font-sans text-gray-900 antialiased">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
