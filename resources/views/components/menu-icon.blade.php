@props(['name', 'size' => 16, 'active' => false])

@php
    /** @var array<string, string> $svgPaths Tabler-style icon paths, 24x24 viewBox */
    $svgPaths = [
        'dashboard' => '<path d="M5 4h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1"/><path d="M5 16h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1"/><path d="M15 12h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1"/><path d="M15 4h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1"/>',
        'iot-insights' => '<path d="M3 12h4l3 8l4 -16l3 8h4"/>',
        'pith-production' => '<path d="M3 21h18"/><path d="M5 21v-12l5 4v-4l5 4h4"/><path d="M19 21v-8l-1.436 -9.574a.5 .5 0 0 0 -.495 -.426h-1.145a.5 .5 0 0 0 -.494 .418l-1.43 8.582"/><path d="M9 17h1"/><path d="M14 17h1"/>',
        'fibre-bales' => '<path d="M12 4l-8 4l8 4l8 -4l-8 -4"/><path d="M4 12l8 4l8 -4"/><path d="M4 16l8 4l8 -4"/>',
        'pith-blocks' => '<path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5"/><path d="M12 12l8 -4.5"/><path d="M12 12l0 9"/><path d="M12 12l-8 -4.5"/>',
        'husk-inventory' => '<path d="M8 13v.01"/><path d="M12 17v.01"/><path d="M12 12v.01"/><path d="M16 14v.01"/><path d="M11 8v.01"/><path d="M13.148 3.476l2.667 1.104a4 4 0 0 0 4.656 6.14l.053 .132a3 3 0 0 1 0 2.296q -.745 1.18 -1.024 1.852q -.283 .684 -.66 2.216a3 3 0 0 1 -1.624 1.623q -1.572 .394 -2.216 .661q -.712 .295 -1.852 1.024a3 3 0 0 1 -2.296 0q -1.203 -.754 -1.852 -1.024q -.707 -.292 -2.216 -.66a3 3 0 0 1 -1.623 -1.624q -.397 -1.577 -.661 -2.216q -.298 -.718 -1.024 -1.852a3 3 0 0 1 0 -2.296q .719 -1.116 1.024 -1.852q .257 -.62 .66 -2.216a3 3 0 0 1 1.624 -1.623q 1.547 -.384 2.216 -.661q .687 -.285 1.852 -1.024a3 3 0 0 1 2.296 0"/>',
        'labour' => '<path d="M11.5 21h-5.5a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v6"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M4 11h16"/><path d="M15 19l2 2l4 -4"/>',
        'growbags' => '<path d="M12 10a6 6 0 0 0 -6 -6h-3v2a6 6 0 0 0 6 6h3"/><path d="M12 14a6 6 0 0 1 6 -6h3v1a6 6 0 0 1 -6 6h-3"/><path d="M12 20l0 -10"/>',
        'reports' => '<path d="M3 13a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1z"/><path d="M15 9a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1z"/><path d="M9 5a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1z"/>',
        'attendance' => '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/>',
    ];

    /** @var array<string, array{default: string, active: string}> $pngIcons Hand-drawn PNG icons with white variant for active/inverted state */
    $pngIcons = [
        'pith-inventory' => ['default' => 'images/menubar-icons/coirpith-icon.png', 'active' => 'images/menubar-icons/coirpith-icon-white.png'],
        'weather' => ['default' => 'images/menubar-icons/weather-icon.png', 'active' => 'images/menubar-icons/weather-icon-white.png'],
    ];
@endphp

@if (isset($pngIcons[$name]))
    <img src="{{ asset($active ? $pngIcons[$name]['active'] : $pngIcons[$name]['default']) }}" alt=""
        width="{{ $size }}" height="{{ $size }}" {{ $attributes->merge(['class' => 'shrink-0']) }} style="width: {{ $size }}px; height: {{ $size }}px;">
@else
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="{{ $size }}" height="{{ $size }}"
        fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
        {{ $attributes->merge(['class' => 'shrink-0']) }}>{!! $svgPaths[$name] ?? '' !!}</svg>
@endif
