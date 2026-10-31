<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="absolute inset-0 rounded-xl blur-lg opacity-30" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});"></div>
                    <div class="relative p-3 rounded-xl shadow-lg" style="background: linear-gradient(135deg, {{ config('colors.pith.total') }}, {{ config('colors.shift.2') }});">
                        <x-menu-icon name="weather" :size="28" :active="true" />
                    </div>
                </div>
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-gray-900">
                        {{ __('Weather Forecast') }}
                    </h2>
                    <p class="text-sm text-gray-500 mt-0.5">5-day outlook with sunlight, rain, wind and coir drying guidance for your site</p>
                </div>
            </div>
            <div class="text-right shrink-0">
                <div class="flex items-center justify-end gap-1.5 text-sm font-medium text-gray-800">
                    <i class="fa-solid fa-rotate text-gray-600 text-xs"></i>
                    @if ($lastUpdatedAt)
                        {{ __('Updated') }} {{ $lastUpdatedAt->isToday() ? $lastUpdatedAt->format('g:i a') : $lastUpdatedAt->format('d M, g:i a') }}
                    @else
                        {{ __('No data yet') }}
                    @endif
                </div>
                <p class="text-xs text-gray-600 mt-0.5">
                    {{ __('Next update') }} {{ $nextUpdateAt->isToday() ? $nextUpdateAt->format('g:i a') : $nextUpdateAt->format('D g:i a') }}
                </p>
            </div>
        </div>
    </x-slot>

    @php
        /** @var array<int, string> Icons available under public/images/weather */
        $availableIcons = ['clear-night', 'cloudy-night', 'cloudy', 'foggy', 'partly-cloudy', 'rainy-night', 'rainy', 'sunny'];
        $selectedDate = request('date', now()->toDateString());
        $compassPoints = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        /** @var array<string, array{dot: string, text: string, chip: string, icon: string}> Styles per CoirDryingAdvisor level */
        $dryingStyles = [
            'excellent' => ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700', 'chip' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'fa-circle-check'],
            'good' => ['dot' => 'bg-green-500', 'text' => 'text-green-700', 'chip' => 'bg-green-50 text-green-700 border-green-200', 'icon' => 'fa-thumbs-up'],
            'fair' => ['dot' => 'bg-amber-500', 'text' => 'text-amber-700', 'chip' => 'bg-amber-50 text-amber-700 border-amber-200', 'icon' => 'fa-circle-minus'],
            'poor' => ['dot' => 'bg-orange-500', 'text' => 'text-orange-700', 'chip' => 'bg-orange-50 text-orange-700 border-orange-200', 'icon' => 'fa-triangle-exclamation'],
            'avoid' => ['dot' => 'bg-red-500', 'text' => 'text-red-700', 'chip' => 'bg-red-50 text-red-700 border-red-200', 'icon' => 'fa-cloud-showers-heavy'],
            'night' => ['dot' => 'bg-slate-400', 'text' => 'text-slate-600', 'chip' => 'bg-slate-50 text-slate-600 border-slate-200', 'icon' => 'fa-moon'],
        ];
    @endphp

    <div class="px-4 py-4 space-y-4">
        <!-- Map + 5-day forecast -->
        <div class="flex flex-col lg:flex-row gap-4">
            <div class="lg:w-[420px] shrink-0 bg-white rounded-xl border border-gray-200 shadow-xs overflow-hidden">
                @if (app()->environment('demo'))
                    <img src="{{ asset('images/demo/site-location-map.png') }}"
                        alt="Site location map" class="w-full h-full min-h-[220px] object-cover">
                @elseif ($siteMapUrl)
                    <img src="{{ $siteMapUrl }}" loading="lazy"
                        alt="Site location map" class="w-full h-full min-h-[220px] object-cover">
                @else
                    <div class="w-full h-full min-h-[220px] flex flex-col items-center justify-center gap-2 bg-gray-50 text-center px-4">
                        <i class="fa-solid fa-location-dot text-2xl text-gray-400"></i>
                        <p class="text-sm font-medium text-gray-600">{{ __('Site location map unavailable') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Set the factory coordinates in settings to see the map.') }}</p>
                    </div>
                @endif
            </div>

            <div class="flex-1 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                @forelse ($dailyWeather as $weather)
                    @php
                        $props = $weather['extendedProps'];
                        $date = \Carbon\Carbon::parse($weather['date']);
                        $isSelected = $weather['date'] === $selectedDate;
                        $sun = (int) ($props['sunlight_classification'] ?? '0');
                        $rain = (int) $props['precipitation_probability'];
                        $windFrom = (int) $props['wind_direction'];
                        $compass = $compassPoints[((int) round($windFrom / 45)) % 8];
                        $icon = in_array($weather['title'], $availableIcons) ? $weather['title'] : 'partly-cloudy';
                    @endphp
                    <a href="{{ route('weather.index', ['team' => $teamId, 'date' => $weather['date']]) }}"
                        class="group flex flex-col bg-white rounded-xl p-3 transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 {{ $isSelected ? 'border-2 border-[#83211b] shadow-md' : 'border border-gray-200 shadow-xs' }}">

                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold {{ $isSelected ? 'text-[#83211b]' : 'text-gray-800' }}">
                                {{ $date->isToday() ? __('Today') : $date->format('D') }}
                            </span>
                            <span class="text-xs font-medium text-gray-600">{{ $date->format('d M') }}</span>
                        </div>

                        <div class="flex items-center justify-center gap-2 my-1.5">
                            <img src="{{ asset('images/weather/' . $icon . '.svg') }}" alt="{{ $weather['title'] }}"
                                title="{{ ucfirst(str_replace('-', ' ', $weather['title'])) }}"
                                class="w-10 h-10 transition-transform duration-200 group-hover:scale-110">
                            <div>
                                <div class="text-lg font-semibold tabular-nums text-gray-800 leading-tight">{{ $props['temperature'] }}&deg;</div>
                                <div class="text-xs text-gray-600 capitalize leading-tight">{{ str_replace('-', ' ', $weather['title']) }}</div>
                            </div>
                        </div>

                        <div class="mt-auto pt-1.5 border-t border-gray-100 space-y-1">
                            {{-- Sunlight --}}
                            <div class="flex items-center gap-1.5" title="Sunlight: {{ $sun }}%">
                                <i class="fa-solid fa-sun text-amber-500 text-xs w-4 text-center"></i>
                                <div class="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full rounded-full bg-amber-400" style="width: {{ $sun }}%"></div>
                                </div>
                                <span class="text-xs font-semibold text-gray-700 w-9 text-right">{{ $sun }}%</span>
                            </div>
                            {{-- Rain chance --}}
                            <div class="flex items-center gap-1.5" title="Chance of rain: {{ $rain }}%{{ $props['rain_classification'] ? ' (' . $props['rain_classification'] . ')' : '' }}">
                                <i class="fa-solid fa-umbrella text-sky-500 text-xs w-4 text-center"></i>
                                <span class="flex-1 text-xs text-gray-700 truncate">{{ $props['rain_classification'] ?? __('Rain') }}</span>
                                <span class="text-xs font-semibold w-9 text-right {{ $rain > 0 ? 'text-gray-700' : 'text-gray-600' }}">{{ $rain }}%</span>
                            </div>
                            {{-- Wind --}}
                            <div class="flex items-center gap-1.5" title="Wind {{ $props['windspeed'] }} km/h from {{ $compass }} ({{ $props['wind_classification'] }})">
                                <i class="fa-solid fa-arrow-up text-gray-700 text-xs w-4 text-center" style="transform: rotate({{ ($windFrom + 180) % 360 }}deg);"></i>
                                <span class="flex-1 text-xs text-gray-700 truncate">{{ $props['wind_classification'] }}</span>
                                <span class="text-xs font-semibold text-gray-700 text-nowrap">{{ $props['windspeed'] }} <span class="font-normal text-gray-600">km/h</span></span>
                            </div>
                        </div>

                        {{-- Coir drying recommendations by day-part --}}
                        @php $drying = $dailyDrying[$weather['date']] ?? []; @endphp
                        @if (!empty($drying))
                            <div class="pt-1.5 mt-1.5 border-t border-gray-100 space-y-0.5">
                                <div class="caps-label">{{ __('Coir Drying') }}</div>
                                @foreach ($drying as $rec)
                                    @php $style = $dryingStyles[$rec['level']] ?? $dryingStyles['fair']; @endphp
                                    <div class="flex items-center gap-1.5" title="{{ $rec['window'] }}: {{ $rec['label'] }} — {{ $rec['advice'] }}">
                                        <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $style['dot'] }}"></span>
                                        <span class="flex-1 text-xs text-gray-700 tabular-nums">{{ $rec['window'] }}</span>
                                        <span class="text-xs font-semibold {{ $style['text'] }}">{{ ucfirst($rec['level']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </a>
                @empty
                    <div class="col-span-full flex items-center justify-center bg-white rounded-xl border border-gray-200 shadow-xs p-8 text-sm text-gray-700">
                        {{ __('No forecast data available yet.') }}
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Hourly breakdown for the selected day -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-xs p-4">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-lg font-semibold text-gray-800">
                    {{ __('Hourly Forecast') }}
                    <span class="text-sm font-normal text-gray-700">· {{ \Carbon\Carbon::parse($selectedDate)->isToday() ? __('Today') . ', ' : '' }}{{ \Carbon\Carbon::parse($selectedDate)->format('D, d M Y') }}</span>
                </h3>
                <span class="hidden sm:flex items-center gap-1.5 text-xs text-gray-600">
                    <i class="fa-solid fa-arrows-left-right"></i> {{ __('Scroll for more hours') }}
                </span>
            </div>

            @if ($hourlyWeather->isEmpty())
                <div class="flex items-center justify-center p-8 text-sm text-gray-700">
                    {{ __('No hourly data available for this day.') }}
                </div>
            @else
                <div class="flex gap-3 overflow-x-auto pb-2 snap-x">
                    @foreach ($hourlyWeather as $weather)
                        @php
                            $props = $weather['extendedProps'];
                            $isNow = $loop->first && \Carbon\Carbon::parse($selectedDate)->isToday();
                            $sun = (int) ($props['sunlight_classification'] ?? '0');
                            $rain = (int) $props['precipitation_probability'];
                            $windFrom = (int) $props['wind_direction'];
                            $compass = $compassPoints[((int) round($windFrom / 45)) % 8];
                            $icon = in_array($props['overall_classification'], $availableIcons) ? $props['overall_classification'] : 'partly-cloudy';
                        @endphp
                        <div class="snap-start shrink-0 w-44 flex flex-col rounded-xl p-3 transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 {{ $isNow ? 'border-2 border-[#83211b] bg-white shadow-md' : 'border border-gray-200 bg-gray-50/50' }}">

                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold tabular-nums {{ $isNow ? 'text-[#83211b]' : 'text-gray-800' }}">
                                    {{ \Carbon\Carbon::createFromTime($weather['hour'])->format('g:i a') }}
                                </span>
                                @if ($isNow)
                                    <span class="caps-label text-white bg-[#83211b] rounded-full px-2 py-0.5">{{ __('Now') }}</span>
                                @endif
                            </div>

                            <div class="flex items-center justify-center gap-2 my-1.5">
                                <img src="{{ asset('images/weather/' . $icon . '.svg') }}" alt="{{ $props['overall_classification'] }}"
                                    title="{{ ucfirst(str_replace('-', ' ', $props['overall_classification'])) }}"
                                    class="w-10 h-10">
                                <div>
                                    <div class="text-base font-semibold tabular-nums text-gray-800 leading-tight">{{ $props['temperature'] }}&deg;</div>
                                    <div class="text-xs text-gray-600 capitalize leading-tight">{{ str_replace('-', ' ', $props['overall_classification']) }}</div>
                                </div>
                            </div>

                            <div class="mt-auto pt-1.5 border-t border-gray-100 space-y-1">
                                <div class="flex items-center gap-1.5" title="Sunlight: {{ $sun }}%">
                                    <i class="fa-solid fa-sun text-amber-500 text-xs w-4 text-center"></i>
                                    <div class="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full bg-amber-400" style="width: {{ $sun }}%"></div>
                                    </div>
                                    <span class="text-xs font-semibold text-gray-700 w-9 text-right">{{ $sun }}%</span>
                                </div>
                                <div class="flex items-center gap-1.5" title="Chance of rain: {{ $rain }}%{{ $props['rain_classification'] ? ' (' . $props['rain_classification'] . ')' : '' }}">
                                    <i class="fa-solid fa-umbrella text-sky-500 text-xs w-4 text-center"></i>
                                    <span class="flex-1 text-xs text-gray-700 truncate">{{ $props['rain_classification'] ?? __('Rain') }}</span>
                                    <span class="text-xs font-semibold w-9 text-right {{ $rain > 0 ? 'text-gray-700' : 'text-gray-600' }}">{{ $rain }}%</span>
                                </div>
                                <div class="flex items-center gap-1.5" title="Wind {{ $props['windspeed'] }} km/h from {{ $compass }} ({{ $props['wind_classification'] }})">
                                    <i class="fa-solid fa-arrow-up text-gray-700 text-xs w-4 text-center" style="transform: rotate({{ ($windFrom + 180) % 360 }}deg);"></i>
                                    <span class="flex-1 text-xs text-gray-700 truncate">{{ $props['wind_classification'] }}</span>
                                    <span class="text-xs font-semibold text-gray-700 text-nowrap">{{ $props['windspeed'] }} <span class="font-normal text-gray-600">km/h</span></span>
                                </div>
                            </div>

                            {{-- Coir drying recommendation for this hour --}}
                            @if (!empty($props['drying']))
                                @php
                                    $rec = $props['drying'];
                                    $style = $dryingStyles[$rec['level']] ?? $dryingStyles['fair'];
                                @endphp
                                <div class="pt-1.5 mt-1.5 border-t border-gray-100">
                                    <div class="flex items-center gap-1.5 rounded-lg border px-2 py-1 {{ $style['chip'] }}">
                                        <i class="fa-solid {{ $style['icon'] }} text-xs"></i>
                                        <span class="text-xs font-semibold">{{ $rec['label'] }}</span>
                                    </div>
                                    <p class="text-xs text-gray-700 leading-snug mt-1.5 line-clamp-2" title="{{ $rec['advice'] }}">{{ $rec['advice'] }}</p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
