@props(['teams', 'currentTeam'])

@php
    $teamsData = $teams->map(function ($team) {
        $displayName = $team->display_name ?? $team->name ?? '';

        $badges = [];
        if ($team->has_pith_module)     $badges[] = 'pith';
        if ($team->has_fibre_module)    $badges[] = 'fibre';
        if ($team->has_growbags_module) $badges[] = 'growbag';

        return [
            'id'       => (int) $team->id,
            'name'     => $displayName,
            'badges'   => $badges,
        ];
    })->values()->toArray();

    // Only offer module chips for modules some listed factory actually runs.
    $moduleChips = collect(['pith' => 'Pith', 'fibre' => 'Fibre', 'growbag' => 'Growbag'])
        ->filter(fn ($label, $key) => collect($teamsData)->contains(fn ($t) => in_array($key, $t['badges'], true)));
@endphp

<div x-data="factorySwitcherPanel({{ json_encode($teamsData) }}, {{ (int) ($currentTeam?->id ?? 0) }})"
     x-init="init()"
     x-effect="if (menu === 'factory') $nextTick(() => $refs.factorySearch.focus())"
     class="w-[360px] bg-white border border-[#e6e0da] rounded-xl shadow-[0_12px_32px_rgba(40,20,10,0.14)] overflow-hidden">

    {{-- Search + module filter --}}
    <div class="grid gap-2.5 px-3 pt-3 pb-2">
        <label class="flex items-center gap-2 h-[38px] px-2.5 border border-[#e6e0da] rounded-lg bg-[#faf8f6]">
            <i class="fa-solid fa-magnifying-glass text-xs text-[#8a817a]"></i>
            <input type="search" x-ref="factorySearch" x-model="search" placeholder="Search factories" autocomplete="off"
                   @keydown.enter.prevent="first && go(first.id)"
                   class="allow-demo flex-1 min-w-0 border-0 bg-transparent p-0 text-sm text-[#1f1b18] placeholder-[#9a938c] focus:outline-none focus:ring-0">
            <kbd class="text-xs font-sans text-[#8a817a] border border-[#e6e0da] rounded px-1.5 py-px" x-text="shortcutHint"></kbd>
        </label>

        @if ($moduleChips->count() > 1)
            <div class="flex gap-1.5">
                <button type="button" @click="module = 'all'"
                        :class="module === 'all' ? 'bg-[#83211b] border-[#83211b] text-white' : 'bg-white border-[#e6e0da] text-[#5a524b] hover:bg-[#f6f2ee]'"
                        class="border rounded-full px-2.5 py-1 text-xs">All</button>
                @foreach ($moduleChips as $key => $label)
                    <button type="button" @click="module = @js($key)"
                            :class="module === @js($key) ? 'bg-[#83211b] border-[#83211b] text-white' : 'bg-white border-[#e6e0da] text-[#5a524b] hover:bg-[#f6f2ee]'"
                            class="border rounded-full px-2.5 py-1 text-xs">{{ $label }}</button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- List --}}
    <div class="max-h-[300px] overflow-y-auto px-1.5 pb-1.5">
        {{-- Unfiltered: current factory, recents, then everything else --}}
        <template x-if="! filtering">
            <div>
                <template x-if="currentFactory">
                    <div class="flex items-center gap-2.5 mt-0.5 mb-1 px-2.5 py-2 rounded-lg bg-[#fbf3f2]">
                        <span class="flex-1 min-w-0 truncate text-sm font-semibold text-[#1f1b18]" x-text="currentFactory.name"></span>
                        <span class="text-xs text-[#6b635c]" x-text="currentFactory.badges.join(' · ')"></span>
                        <i class="fa-solid fa-check text-sm text-[#83211b]"></i>
                    </div>
                </template>

                <template x-if="recentlyVisited.length > 0">
                    <div>
                        <div class="px-2 pt-2 pb-1 text-xs text-[#8a817a]">Recent</div>
                        <template x-for="t in recentlyVisited" :key="'recent-' + t.id">
                            <div @click="go(t.id)" class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg cursor-pointer hover:bg-[#f6f2ee]">
                                <span class="flex-1 min-w-0 truncate text-sm text-[#1f1b18]" x-text="t.name"></span>
                                <span class="text-xs text-[#8a817a]" x-text="t.badges.join(' · ')"></span>
                            </div>
                        </template>
                    </div>
                </template>

                <div x-show="otherTeams.length > 0" class="px-2 pt-2.5 pb-1 text-xs text-[#8a817a]">All factories</div>
            </div>
        </template>

        <template x-for="t in listedTeams" :key="'factory-' + t.id">
            <div @click="go(t.id)"
                 :class="t.id === currentId ? 'bg-[#fbf3f2]' : 'hover:bg-[#f6f2ee]'"
                 class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg cursor-pointer">
                <span class="flex-1 min-w-0 truncate text-sm text-[#1f1b18]" x-text="t.name"></span>
                <span class="text-xs text-[#8a817a]" x-text="t.badges.join(' · ')"></span>
                <i x-show="t.id === currentId" class="fa-solid fa-check text-sm text-[#83211b]"></i>
            </div>
        </template>

        <div x-show="filtering && listedTeams.length === 0" x-cloak class="px-2 py-5 text-center text-sm text-[#8a817a]">
            No factories match
        </div>
    </div>

    {{-- Footer --}}
    <div class="flex items-center justify-between gap-3 px-3 py-2.5 border-t border-[#efe9e3] bg-[#faf8f6] text-[13px]">
        <span class="text-[#8a817a]">{{ count($teamsData) }} {{ Str::plural('factory', count($teamsData)) }}</span>
        @if (Auth::user()->isSuperadmin())
            <a href="{{ route('teams.create') }}"
               class="inline-flex items-center gap-1.5 rounded-lg bg-[#83211b] px-2.5 py-1.5 font-semibold text-white hover:bg-[#6b1a15] hover:text-white">
                <i class="fa-solid fa-plus text-xs"></i>New factory
            </a>
        @endif
    </div>
</div>

<script>
if (typeof factorySwitcherPanel === 'undefined') {
    function factorySwitcherPanel(teams, currentId) {
        return {
            teams,
            currentId: Number(currentId),
            search: '',
            module: 'all',
            recentIds: [],
            shortcutHint: /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘K' : 'Ctrl K',

            init() {
                try { this.recentIds = JSON.parse(localStorage.getItem('pulse_recently_visited') || '[]').map(Number); } catch (_) {}
            },

            get filtering() {
                return this.search.trim() !== '' || this.module !== 'all';
            },

            get currentFactory() {
                return this.teams.find(t => t.id === this.currentId) || null;
            },

            get filteredTeams() {
                const q = this.search.trim().toLowerCase();
                return this.teams
                    .filter(t => this.module === 'all' || t.badges.includes(this.module))
                    .filter(t => ! q || t.name.toLowerCase().includes(q))
                    .sort((a, b) => a.name.localeCompare(b.name));
            },

            get otherTeams() {
                return this.filteredTeams.filter(t => t.id !== this.currentId);
            },

            // Unfiltered, the current factory is pinned above the list, so leave it out here.
            get listedTeams() {
                return this.filtering ? this.filteredTeams : this.otherTeams;
            },

            get first() {
                return this.listedTeams[0] || null;
            },

            get recentlyVisited() {
                return this.recentIds
                    .map(id => this.teams.find(t => t.id === id))
                    .filter(t => t && t.id !== this.currentId)
                    .slice(0, 3);
            },

            go(id) {
                const n = Number(id);
                if (n === this.currentId) return;

                // Remember the factory being left so it shows under "Recent".
                let recent = this.recentIds.filter(i => i !== this.currentId && i !== n);
                recent.unshift(this.currentId);
                this.recentIds = recent.slice(0, 10);
                try { localStorage.setItem('pulse_recently_visited', JSON.stringify(this.recentIds)); } catch (_) {}

                // Keep the user on the same page in the new factory where the URL allows it.
                var currentPath = window.location.pathname;
                var newPath;
                if (/^\/\d+/.test(currentPath)) {
                    newPath = currentPath.replace(/^\/\d+/, '/' + n);
                } else if (/^\/teams\/\d+/.test(currentPath)) {
                    newPath = currentPath.replace(/^\/teams\/\d+/, '/teams/' + n);
                } else {
                    newPath = '/' + n + '/dashboard';
                }
                window.location.href = newPath;
            },
        };
    }
}
</script>
