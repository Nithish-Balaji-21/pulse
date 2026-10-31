@props(['href', 'active' => false, 'icon', 'label', 'short' => null])

{{--
    Sidebar navigation item. Expanded: icon + label in a row.
    Collapsed (Alpine `reduce_container`): icon stacked above a short label,
    Zoho Books style. Requires an ancestor with `reduce_container` in x-data.
--}}
<div class="hidden sm:-my-px sm:flex flex-col relative sm:mb-0.5 text-nowrap">
    <a href="{{ $href }}"
        class="menu-link flex text-sm w-full py-2 rounded-lg transition-all duration-300 cursor-pointer items-center px-2 {{ $active ? 'bg-[#83211b] text-white hover:bg-[#83211b]' : 'text-[#4b443f] hover:bg-[#4b443f]/8' }}">
        <div class="menu-icon-box shrink-0 flex items-center w-6">
            {{-- PNG icons carry their own white variant for the active state; #83211b mirrors COLOR_PRIMARY --}}
            <x-menu-icon :name="$icon" :active="$active" />
        </div>
        <span x-show="!reduce_container" class="flex-1">{{ $label }}</span>
        <span x-show="reduce_container" x-cloak class="text-xs font-medium leading-tight text-center whitespace-normal">{{ $short ?? $label }}</span>
    </a>
</div>
