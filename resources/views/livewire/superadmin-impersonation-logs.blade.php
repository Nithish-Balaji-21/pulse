<div>
    <!-- Header + Filters -->
    <div class="flex flex-wrap justify-between items-center gap-3 mt-4 mb-4 !pl-4">
        <div>
            <h2 class="text-lg font-semibold">Impersonation Audit Log</h2>
            <p class="text-xs text-gray-500 mt-0.5">
                Every superadmin impersonation, and every change made during one. Read-only sessions record no changes.
            </p>
        </div>

        <div class="flex flex-wrap gap-2 items-center mr-4">
            <input type="text"
                   wire:model.live.debounce.400ms="searchUser"
                   placeholder="Superadmin or user name..."
                   class="w-56 form-input border border-1 rounded-lg text-sm px-3 py-1.5 border-gray-400 hover:border-[#861e1a] focus:border-[#861e1a] focus:ring-0">

            <select wire:model.live="filterAction"
                    class="form-select border border-1 rounded-lg text-sm px-3 py-1.5 border-gray-400 focus:border-[#861e1a] focus:ring-0">
                <option value="">All actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}">{{ str_replace('_', ' ', ucfirst($action)) }}</option>
                @endforeach
            </select>

            <label class="flex items-center gap-1.5 text-sm text-gray-700">
                <input type="checkbox" wire:model.live="mutationsOnly" class="rounded border-gray-400">
                Changes only
            </label>

            <button wire:click="resetFilters"
                    class="px-3 py-1.5 bg-gray-200 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-300">
                Reset
            </button>
        </div>
    </div>

    @if ($filterSession !== '')
        <div class="mx-4 mb-3 px-3 py-2 bg-indigo-50 border border-indigo-200 rounded-lg text-sm text-indigo-900 flex items-center justify-between">
            <span>Showing a single impersonation session — <code class="text-xs">{{ $filterSession }}</code></span>
            <button wire:click="$set('filterSession', '')" class="text-xs font-semibold underline">Show all sessions</button>
        </div>
    @endif

    <div class="mx-4 overflow-x-auto bg-white rounded-lg shadow">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 caps-label">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">When</th>
                    <th class="px-4 py-2 text-left font-semibold">Superadmin</th>
                    <th class="px-4 py-2 text-left font-semibold">Acting as</th>
                    <th class="px-4 py-2 text-left font-semibold">Factory</th>
                    <th class="px-4 py-2 text-left font-semibold">Action</th>
                    <th class="px-4 py-2 text-left font-semibold">Record</th>
                    <th class="px-4 py-2 text-left font-semibold">Changes</th>
                    <th class="px-4 py-2 text-right font-semibold">Session</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-gray-900">
                @php
                    $actionStyles = [
                        'start' => 'bg-indigo-100 text-indigo-800',
                        'stop' => 'bg-gray-100 text-gray-800',
                        'write_mode_enabled' => 'bg-red-100 text-red-800',
                        'write_mode_disabled' => 'bg-green-100 text-green-800',
                        'created' => 'bg-emerald-100 text-emerald-800',
                        'updated' => 'bg-amber-100 text-amber-800',
                        'deleted' => 'bg-rose-100 text-rose-800',
                    ];
                @endphp
                @forelse ($logs as $log)
                    <tr class="hover:bg-gray-50/60 align-top">
                        <td class="px-4 py-2 whitespace-nowrap tabular-nums">
                            {{ $log->created_at?->format('d M Y g:i:s a') }}
                        </td>
                        <td class="px-4 py-2">{{ $log->impersonator?->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $log->impersonated?->name ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $log->team?->name ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 text-xs font-semibold rounded whitespace-nowrap {{ $actionStyles[$log->action] ?? 'bg-gray-100 text-gray-800' }}">
                                {{ str_replace('_', ' ', $log->action) }}
                            </span>
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap tabular-nums">
                            @if ($log->subject_type)
                                {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="px-4 py-2 max-w-md">
                            @if ($log->changes)
                                <details>
                                    <summary class="cursor-pointer text-xs text-blue-700">
                                        {{ count($log->changes) }} field{{ count($log->changes) === 1 ? '' : 's' }}
                                    </summary>
                                    <pre class="mt-1 text-xs bg-gray-50 p-2 rounded overflow-x-auto">{{ json_encode($log->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button wire:click="showSession('{{ $log->session_uuid }}')"
                                    title="Show only this impersonation session"
                                    class="text-xs text-blue-700 underline">
                                {{ Str::limit($log->session_uuid, 8, '') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-gray-500">
                            No impersonation activity recorded.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mx-4 my-4">
        {{ $logs->links() }}
    </div>
</div>
