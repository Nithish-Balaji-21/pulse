@php
    // Group title => its link labels, for the client-side search filter.
    $searchIndex = collect($groups)->mapWithKeys(fn ($g) => [$g['title'] => array_column($g['items'], 'label')])->all();
@endphp

<div x-data="{
        q: '',
        index: @js($searchIndex),
        matches(label, group) {
            const q = this.q.trim().toLowerCase();
            return ! q || label.toLowerCase().includes(q) || group.toLowerCase().includes(q);
        },
        groupMatches(group) {
            return this.index[group].some(label => this.matches(label, group));
        },
        get anyMatch() {
            return Object.keys(this.index).some(group => this.groupMatches(group));
        },
     }"
     x-effect="if (menu === 'settings') $nextTick(() => $refs.settingsSearch.focus())"
     class="w-[720px] max-w-[86vw] bg-white border border-[#e6e0da] rounded-xl shadow-[0_12px_32px_rgba(40,20,10,0.14)] overflow-hidden">

    <div class="flex items-center gap-3 px-4 py-3.5 border-b border-[#efe9e3]">
        <div class="grid gap-0.5 flex-1 min-w-0">
            <span class="text-base font-semibold text-[#1f1b18]">Settings</span>
            <span class="text-xs text-[#8a817a] truncate">Applies to {{ $team?->display_name }} only</span>
        </div>
        <label class="flex items-center gap-2 h-9 w-56 px-2.5 border border-[#e6e0da] rounded-lg bg-[#faf8f6]">
            <i class="fa-solid fa-magnifying-glass text-xs text-[#8a817a]"></i>
            <input type="search" x-ref="settingsSearch" x-model="q" placeholder="Find a setting" autocomplete="off"
                   class="allow-demo flex-1 min-w-0 border-0 bg-transparent p-0 text-[13px] text-[#1f1b18] placeholder-[#9a938c] focus:outline-none focus:ring-0">
        </label>
    </div>

    <div class="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-1 p-3">
        @foreach ($groups as $group)
            <div x-show="groupMatches(@js($group['title']))"
                 class="grid content-start gap-0.5 p-1.5">
                <div class="flex items-center gap-2 px-2 pt-1 pb-2 text-[13px] font-semibold text-[#1f1b18]">
                    <i class="fa-solid {{ $group['icon'] }} text-sm text-[#83211b] w-4 text-center"></i>
                    {{ $group['title'] }}
                </div>
                @foreach ($group['items'] as $item)
                    <a href="{{ $item['url'] }}" x-show="matches(@js($item['label']), @js($group['title']))"
                       class="px-2 py-[7px] rounded-md text-sm text-[#3d3631] hover:bg-[#fbf3f2] hover:text-[#83211b] hover:no-underline">{{ $item['label'] }}</a>
                @endforeach
            </div>
        @endforeach
    </div>

    <div x-show="! anyMatch"
         x-cloak class="px-4 pb-6 pt-2 text-center text-sm text-[#8a817a]">
        No settings match
    </div>
</div>
