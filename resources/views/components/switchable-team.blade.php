@props(['team', 'component' => 'dropdown-link'])

@php
    $textClass = !($team->pulse->is_active ?? false) ? 'text-gray-400' : '';
    $teamId = (int) $team->id;
    $currentTeam = Auth::user()->activeTeam();

    $moduleBadges = [];
    if (Auth::user()->is_superadmin) {
        if ($team->has_pith_module)    $moduleBadges[] = ['label' => 'pith',    'class' => 'bg-[#fde8d8] text-[#c25e19]'];
        if ($team->has_fibre_module)   $moduleBadges[] = ['label' => 'fibre',   'class' => 'bg-[#e0f2fe] text-[#0284c7]'];
        if ($team->has_growbags_module) $moduleBadges[] = ['label' => 'growbag', 'class' => 'bg-[#dcfce7] text-[#15803d]'];
    }
@endphp

@if ($currentTeam?->id === $team->id)
    {{-- Active / current team row --}}
    <x-dynamic-component :component="$component" href="#" x-on:click.prevent="" class="!px-4 bg-[#fef6f0]">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 truncate">
                <span class="truncate font-semibold text-[#83211b] {{ $textClass }}">{{ $team->display_name }}</span>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                @foreach ($moduleBadges as $badge)
                    <span class="shrink-0 rounded-full px-1.5 py-px text-xs font-semibold leading-tight {{ $badge['class'] }}">
                        {{ $badge['label'] }}
                    </span>
                @endforeach
                <svg class="size-4 shrink-0 text-[#83211b]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
            </div>
        </div>
    </x-dynamic-component>
@else
    {{-- Switchable team row --}}
    <button
        type="button"
        data-team-id="{{ $teamId }}"
        onclick="switchToTeam(this)"
        class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-50 focus:outline-hidden transition duration-150 ease-in-out group">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 truncate">
                <span class="truncate {{ $textClass }}">{{ $team->display_name }}</span>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                @foreach ($moduleBadges as $badge)
                    <span class="shrink-0 rounded-full px-1.5 py-px text-xs font-semibold leading-tight {{ $badge['class'] }}">
                        {{ $badge['label'] }}
                    </span>
                @endforeach
            </div>
        </div>
    </button>
@endif