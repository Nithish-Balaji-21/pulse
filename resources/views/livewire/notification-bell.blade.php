<div class="relative" @click.outside="menu === 'bell' && close()">
    <button type="button" @click="toggle('bell')" title="Notifications" aria-label="Notifications"
            :class="menu === 'bell' ? 'bg-[#f3eee9]' : 'bg-transparent'"
            class="relative size-9 grid place-items-center rounded-[10px] text-[#3d3631] hover:bg-[#f3eee9] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#83211b]/30">
        <i class="fa-regular fa-bell text-[18px]"></i>
        @if ($unreadCount > 0)
            <span class="absolute top-0.5 right-0 min-w-4 h-4 px-1 box-border rounded-full bg-[#c62828] text-white text-[10px] font-bold leading-none grid place-items-center border-2 border-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="menu === 'bell'" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="absolute top-[calc(100%+8px)] -right-12 w-[360px] bg-white border border-[#e6e0da] rounded-xl shadow-[0_12px_32px_rgba(40,20,10,0.14)] overflow-hidden z-20">

        <div class="flex items-center justify-between px-3.5 py-3 border-b border-[#efe9e3]">
            <span class="text-base font-semibold text-[#1f1b18]">Notifications</span>
            @if ($unreadCount > 0 && $canMarkRead)
                <button type="button" wire:click="markAllRead" class="allow-demo text-[13px] text-[#83211b] hover:underline">Mark all read</button>
            @endif
        </div>

        <div class="max-h-[420px] overflow-y-auto">
            @forelse ($notifications as $notification)
                <div wire:key="notification-{{ $notification['id'] }}"
                     @if ($notification['unread'] && $canMarkRead) wire:click="markRead({{ $notification['id'] }})" @endif
                     class="flex gap-3 px-3.5 py-3 border-b border-[#f4efea] {{ $notification['unread'] ? 'bg-[#fffaf8] cursor-pointer' : 'bg-white' }}">
                    <span class="size-8 flex-none rounded-lg grid place-items-center {{ $notification['unread'] ? 'bg-[#fbf3f2] text-[#83211b]' : 'bg-[#f3eee9] text-[#6b635c]' }}">
                        <i class="fa-solid {{ $notification['icon'] }} text-sm"></i>
                    </span>
                    <span class="grid gap-0.5 flex-1 min-w-0">
                        <span class="text-sm leading-snug text-[#1f1b18] {{ $notification['unread'] ? 'font-semibold' : '' }}">{{ $notification['subject'] }}</span>
                        <span class="text-[13px] leading-snug text-[#6b635c] whitespace-pre-line line-clamp-3">{{ $notification['body'] }}</span>
                        <span class="text-xs text-[#8a817a]">{{ $notification['meta'] }}</span>
                    </span>
                    <span class="size-2 mt-1.5 flex-none rounded-full {{ $notification['unread'] ? 'bg-[#c62828]' : 'bg-transparent' }}"></span>
                </div>
            @empty
                <div class="px-6 py-10 text-center">
                    <i class="fa-regular fa-bell-slash text-2xl text-[#c9c0b8]"></i>
                    <p class="mt-2 text-sm text-[#8a817a]">No notifications in the last 7 days</p>
                </div>
            @endforelse
        </div>

        @can('view-notification-preferences')
            <div class="flex justify-end px-3.5 py-2.5 bg-[#faf8f6] text-[13px]">
                <a href="{{ route('notification-preferences.index', ['team' => $teamId]) }}" class="text-[#6b635c] hover:text-[#83211b]">Notification preferences</a>
            </div>
        @endcan
    </div>
</div>
