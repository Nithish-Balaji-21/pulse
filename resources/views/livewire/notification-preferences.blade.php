<div>
    <div class="flex items-center justify-between mt-5 px-4">
        <h2 class="font-semibold text-lg">
            {{ __('Notification preferences') }}
        </h2>
        <div class="text-sm text-green-600" x-data="{ shown: false }"
            x-on:saved.window="shown = true; setTimeout(() => shown = false, 2000)" x-show="shown" x-cloak>
            {{ __('Saved') }}
        </div>
        <div class="text-sm text-gray-500" wire:loading>{{ __('Saving…') }}</div>
    </div>

    <div class="py-2 px-4">
        @unless ($canReceiveNotifications)
            <div class="mt-2 rounded border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                {{ __('Notifications from :factory go to owners, admins and supervisors only, so there is nothing to set up here.', ['factory' => $teamName]) }}
                @if ($teamRole)
                    {{ __('Your role here is :role.', ['role' => $teamRole]) }}
                @endif
            </div>
        @else
        <p class="text-sm text-gray-600">
            {{ __('Choose what :factory sends you.', ['factory' => $teamName]) }}
            @if ($teamRole)
                <span class="text-gray-500">{{ __('Your role here is :role.', ['role' => $teamRole]) }}</span>
            @endif
        </p>

        @foreach ($matrix as $group)
            <div class="mt-6" wire:key="group-{{ Str::slug($group['group']) }}">
                <h3 class="caps-label text-gray-800">{{ $group['group'] }}</h3>

                <div class="mt-2 overflow-x-auto">
                    <table class="w-full border-t border-gray-200">
                        <thead>
                            <tr class="table-header text-xs border-b border-gray-300">
                                <th class="px-4 py-2 text-left font-semibold">{{ __('Notification') }}</th>
                                <th class="px-4 py-2 text-center font-semibold w-24">{{ __('Email') }}</th>
                                <th class="px-4 py-2 text-center font-semibold w-24">{{ __('Push') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['types'] as $type)
                                <tr class="border-b border-gray-200" wire:key="type-{{ $type['type'] }}">
                                    <td class="px-4 py-2 text-sm">
                                        <div class="font-medium">{{ $type['label'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $type['description'] }}</div>
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        @if ($type['email_supported'])
                                            <input type="checkbox" class="rounded border-gray-300"
                                                wire:model.live="preferences.{{ $type['type'] }}.email"
                                                aria-label="{{ $type['label'] }} email">
                                        @else
                                            <span class="text-xs text-gray-400">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        @if ($type['push_supported'])
                                            <input type="checkbox" class="rounded border-gray-300"
                                                wire:model.live="preferences.{{ $type['type'] }}.push"
                                                aria-label="{{ $type['label'] }} push">
                                        @else
                                            <span class="text-xs text-gray-400">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        @if ($isSuperadmin)
            <div class="mt-10">
                <h3 class="caps-label text-gray-800">{{ __('Factories') }}</h3>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('You receive notifications from every factory. Switch one off to stop all of its emails and push notifications.') }}
                </p>

                <div class="mt-2 overflow-x-auto">
                    <table class="w-full border-t border-gray-200">
                        <thead>
                            <tr class="table-header text-xs border-b border-gray-300">
                                <th class="px-4 py-2 text-left font-semibold">{{ __('Factory') }}</th>
                                <th class="px-4 py-2 text-center font-semibold w-32">{{ __('Notify me') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($factories as $factory)
                                <tr class="border-b border-gray-200" wire:key="factory-{{ $factory['id'] }}">
                                    <td class="px-4 py-2 text-sm">
                                        {{ $factory['name'] }}
                                        @if ($factory['id'] === $teamId)
                                            <span class="ml-2 text-xs text-gray-500">{{ __('(current)') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <input type="checkbox" class="rounded border-gray-300"
                                            wire:model.live="factoryEnabled.{{ $factory['id'] }}"
                                            aria-label="{{ $factory['name'] }} notifications">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
        @endunless
    </div>
</div>
