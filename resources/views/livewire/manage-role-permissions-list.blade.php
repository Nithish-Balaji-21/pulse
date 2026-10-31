@php
    $roleStyles = [
        'owner' => [
            'bg' => 'bg-rose-50 border border-rose-100',
            'icon' => 'fa-solid fa-crown',
            'iconColor' => 'text-rose-700',
            'textColor' => 'text-rose-900 group-hover:text-rose-700',
        ],
        'admin' => [
            'bg' => 'bg-orange-50 border border-orange-100',
            'icon' => 'fa-regular fa-user',
            'iconColor' => 'text-orange-600',
            'textColor' => 'text-orange-955 font-semibold group-hover:text-orange-600',
        ],
        'supervisor' => [
            'bg' => 'bg-amber-50 border border-amber-100',
            'icon' => 'fa-solid fa-user-shield',
            'iconColor' => 'text-amber-600',
            'textColor' => 'text-amber-955 font-semibold group-hover:text-amber-600',
        ],
        'staff' => [
            'bg' => 'bg-yellow-50 border border-yellow-100',
            'icon' => 'fa-solid fa-users',
            'iconColor' => 'text-yellow-600',
            'textColor' => 'text-yellow-955 font-semibold group-hover:text-yellow-600',
        ],
        'reader' => [
            'bg' => 'bg-red-50 border border-red-100',
            'icon' => 'fa-regular fa-eye',
            'iconColor' => 'text-red-600',
            'textColor' => 'text-red-955 font-semibold group-hover:text-red-600',
        ],
    ];
@endphp

<div class="w-full max-w-5xl mx-auto px-4 py-4">
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="javascript:history.back()"
               class="text-gray-500 hover:text-[#8B1E17] transition shrink-0 pr-1"
               title="Go Back">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="p-2 bg-[#8B1E17] text-white rounded-lg shadow-sm shrink-0">
                <i class="fa-solid fa-user-shield text-sm"></i>
            </div>
            <div>
                <h2 class="text-lg font-semibold text-[#8B1E17] leading-none">{{ __('Roles') }}</h2>
                <p class="text-xs font-normal text-gray-500 mt-1">Choose a role to view or configure what it can do.</p>
            </div>
        </div>
    </x-slot>

    @if (session('success'))
        <div class="mb-3 rounded-md bg-green-50 border border-green-200 text-green-800 px-3 py-2 text-sm">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
        <div class="hidden sm:grid grid-cols-12 border-b border-gray-200 bg-gray-50/50 py-2.5 px-4 caps-label">
            <div class="col-span-3">Role</div>
            <div class="col-span-7">Description</div>
            <div class="col-span-2 text-right">Members</div>
        </div>
        
        <div class="divide-y divide-gray-100">
            @foreach ($rolesList as $role)
                @php
                    $style = $roleStyles[$role['key']] ?? [
                        'bg' => 'bg-gray-50 border border-gray-100',
                        'icon' => 'fa-solid fa-user',
                        'iconColor' => 'text-gray-600',
                        'textColor' => 'text-gray-900 group-hover:text-indigo-600',
                    ];
                @endphp
                <a href="{{ route('roles.edit', ['team' => $teamId, 'roleKey' => $role['key']]) }}"
                   class="grid grid-cols-12 items-center py-3.5 px-4 hover:bg-slate-50/30 transition group">
                    <div class="col-span-12 sm:col-span-3 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center border {{ $style['bg'] }} shrink-0">
                            <i class="{{ $style['icon'] }} {{ $style['iconColor'] }} text-xs"></i>
                        </div>
                        <span class="font-semibold text-sm {{ $style['textColor'] }}">{{ $role['name'] }}</span>
                    </div>
                    <div class="col-span-12 sm:col-span-7 text-xs sm:text-sm text-gray-500 font-normal pr-4 mt-1 sm:mt-0">
                        <p class="line-clamp-2">{{ $role['description'] }}</p>
                    </div>
                    <div class="col-span-12 sm:col-span-2 flex items-center justify-between sm:justify-end gap-3 text-right mt-2 sm:mt-0">
                        <span class="text-xs font-normal text-gray-400 bg-gray-50 px-2 py-0.5 rounded border border-gray-100 shrink-0">
                            {{ $role['member_count'] }} {{ Str::plural('user', $role['member_count']) }}
                        </span>
                        <i class="fa-solid fa-chevron-right text-gray-300 text-xs group-hover:text-indigo-500 group-hover:translate-x-0.5 transition duration-150"></i>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</div>
