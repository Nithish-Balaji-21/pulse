@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="@assetVersion('js/geofence-editor.js')"></script>
@endpush

<div>
    <div class="flex items-center justify-between mt-5 px-4 gap-3 flex-wrap">
        <div>
            <h2 class="font-semibold text-lg">{{ __('Factory Settings: Geofence Boundaries') }}</h2>
            <p class="text-sm text-gray-600 mt-0.5">
                {{ __('Boundaries used:') }}
                <span class="font-semibold">{{ $usedBoundaries }} / {{ $maxBoundaries }}</span>
                @if ($atCap)
                    <span class="text-amber-700 font-semibold ms-1">{{ __('cap reached') }}</span>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if ($successMessage)
                <div class="text-green-700 text-sm font-semibold">{{ $successMessage }}</div>
            @endif
            @if ($errorMessage)
                <div class="text-red-700 text-sm font-semibold">{{ $errorMessage }}</div>
            @endif
            @if ($canCreate && ! $atCap && ! $editorOpen)
                <button type="button" wire:click="startCreate('circle')" class="add-btn text-sm">
                    <i class="fa-regular fa-circle text-sm"></i> {{ __('Add circle') }}
                </button>
                <button type="button" wire:click="startCreate('polygon')" class="add-btn text-sm">
                    <i class="fa-solid fa-draw-polygon text-sm"></i> {{ __('Add polygon') }}
                </button>
            @endif
        </div>
    </div>

    @if ($editorOpen)
        <div class="mx-4 mt-4 border border-gray-300 rounded-lg bg-white p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold">
                    {{ $form['id'] ? __('Edit boundary') : __('New boundary') }}
                </h3>
                <button type="button" wire:click="closeEditor" class="text-gray-500 hover:text-gray-800">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <p class="text-xs text-gray-600 mb-3">
                @if ($form['boundary_type'] === 'polygon')
                    {{ __('Click the map to add vertices (3–32). Click a vertex to remove it.') }}
                @else
                    {{ __('Click the map to place the circle centre, then adjust the radius.') }}
                @endif
            </p>
            <form wire:submit.prevent="save" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                    <label class="text-sm block mb-1">{{ __('Name') }}</label>
                    <input type="text" wire:model="form.name" maxlength="100" class="text-sm input-type w-full" @disabled(($form['id'] && ! $canEdit) || (! $form['id'] && ! $canCreate))>
                    @error('form.name') <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="text-sm block mb-1">{{ __('Type') }}</label>
                    <select wire:model="form.type" class="text-sm input-type w-full">
                        @foreach ($typeLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('form.type') <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="text-sm block mb-1">{{ __('Shape') }}</label>
                    <select wire:model.live="form.boundary_type" class="text-sm input-type w-full">
                        <option value="circle">{{ __('Circle') }}</option>
                        <option value="polygon">{{ __('Polygon') }}</option>
                    </select>
                    @error('form.boundary_type') <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="text-sm block mb-1">{{ __('Status') }}</label>
                    <select wire:model="form.status" class="text-sm input-type w-full">
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>
                @if ($form['boundary_type'] === 'circle')
                    <div class="md:col-span-2">
                        <label class="text-sm block mb-1">{{ __('Radius') }} ({{ $form['radius'] ?? 80 }} m)</label>
                        <input id="geofence-radius" type="range" min="10" max="2000" step="5"
                            wire:model.live="form.radius"
                            x-on:input="GeofenceEditor.resizeCircle(Number($event.target.value))"
                            class="w-full">
                    </div>
                @else
                    <div class="flex items-end">
                        <button type="button" class="cancel-btn text-sm" x-on:click="GeofenceEditor.removeLastVertex()">
                            {{ __('Remove last point') }}
                        </button>
                    </div>
                @endif
                <div class="flex items-end justify-end gap-2 md:col-span-2 lg:col-span-1">
                    <button type="submit" class="save-btn text-sm" wire:loading.attr="disabled">
                        <i class="fa-solid {{ $form['id'] ? 'fa-rotate' : 'fa-plus' }}"></i>
                        {{ $form['id'] ? __('Update') : __('Save') }}
                    </button>
                    <button type="button" wire:click="closeEditor" class="cancel-btn text-sm">{{ __('Cancel') }}</button>
                </div>
            </form>
        </div>
    @endif

    <div class="px-4 mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4 pb-6">
        <div class="lg:col-span-1 border border-gray-300 rounded-lg bg-white overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="table-header text-xs border-b border-gray-300">
                        <th class="py-2 pe-1 font-semibold ps-3 text-left">{{ __('Name') }}</th>
                        <th class="py-2 pe-1 font-semibold">{{ __('Shape') }}</th>
                        <th class="py-2 pe-1 font-semibold">{{ __('Status') }}</th>
                        <th class="py-2 pe-1 font-semibold">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($locations as $location)
                        <tr wire:key="geofence-{{ $location['id'] }}"
                            wire:click="focus({{ $location['id'] }})"
                            onclick="if (event.target.closest('button')) return; GeofenceEditor.focusById({{ (int) $location['id'] }})"
                            class="text-xs border-b border-gray-200 cursor-pointer hover:bg-gray-50 {{ ($focusedId ?? $form['id'] ?? null) == $location['id'] ? 'bg-amber-50 hover:bg-amber-50' : '' }}">
                            <td class="text-left text-sm py-3 ps-3">
                                <div class="capitalize">{{ $location['name'] }}</div>
                                <div class="text-xs text-gray-500">{{ $typeLabels[$location['type']] ?? $location['type'] }}</div>
                            </td>
                            <td class="text-center capitalize">{{ $location['boundary_type'] }}</td>
                            <td class="text-center">
                                @if ($canEdit)
                                    <button type="button" wire:click.stop="confirmToggleStatus({{ $location['id'] }})"
                                        class="font-semibold underline decoration-dotted underline-offset-2 {{ $location['status'] === 'active' ? 'text-green-600 hover:text-green-800' : 'text-red-500 hover:text-red-700' }}"
                                        title="{{ $location['status'] === 'active' ? __('Deactivate this boundary') : __('Activate this boundary') }}">
                                        {{ ucfirst($location['status']) }}
                                    </button>
                                @else
                                    <span class="{{ $location['status'] === 'active' ? 'text-green-600' : 'text-red-500' }}">
                                        {{ ucfirst($location['status']) }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-center py-2 whitespace-nowrap space-x-1">
                                @if ($canEdit)
                                    <button type="button" wire:click.stop="edit({{ $location['id'] }})" class="edit-btn btn-xs">
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </button>
                                @endif
                                @if ($canDelete)
                                    <button type="button" wire:click.stop="confirmDelete({{ $location['id'] }})" class="delete-btn btn-xs">
                                        <i class="fa-regular fa-trash-can"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-sm py-8 text-gray-500">
                                {{ __('No geofence boundaries yet. Draw a circle or polygon on the map.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="lg:col-span-2 border border-gray-300 rounded-lg overflow-hidden bg-white relative isolate z-0" wire:ignore
            data-search-url="{{ route('geofences.search', ['team' => $teamId]) }}">
            <div id="geofence-map-toolbar" class="absolute z-[1000] left-14 right-3 top-3 flex flex-col gap-2 pointer-events-none">
                <div class="flex flex-wrap items-start gap-2 pointer-events-auto">
                    <div class="relative min-w-[12rem] flex-1">
                        <input id="geofence-place-search" type="search" autocomplete="off"
                            placeholder="{{ __('Search place') }}"
                            class="w-full text-sm input-type bg-white shadow-sm">
                        <div id="geofence-search-results" class="hidden absolute left-0 right-0 mt-1 bg-white border border-gray-200 rounded shadow-lg max-h-48 overflow-y-auto text-sm"></div>
                    </div>
                    <input id="geofence-goto-lat" type="text" inputmode="decimal" placeholder="{{ __('Lat') }}"
                        class="w-28 text-sm input-type bg-white shadow-sm">
                    <input id="geofence-goto-lng" type="text" inputmode="decimal" placeholder="{{ __('Lng') }}"
                        class="w-28 text-sm input-type bg-white shadow-sm">
                    <button type="button" id="geofence-goto-btn" class="save-btn text-sm shadow-sm">{{ __('Go') }}</button>
                    <div class="inline-flex rounded overflow-hidden border border-gray-300 bg-white shadow-sm text-sm">
                        <button type="button" id="geofence-layer-street" class="px-3 py-1.5 font-semibold bg-[#83211b] text-white">{{ __('Street') }}</button>
                        <button type="button" id="geofence-layer-satellite" class="px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50">{{ __('Satellite') }}</button>
                    </div>
                </div>
                <div id="geofence-map-error" class="hidden text-xs font-semibold text-red-700 bg-white/90 rounded px-2 py-1 w-fit pointer-events-auto"></div>
            </div>
            <div id="geofence-map" class="w-full" style="height: 560px;"></div>
        </div>
    </div>

    @if ($confirmingStatusId)
        @php
            $statusTarget = collect($locations)->firstWhere('id', $confirmingStatusId);
            $statusIsActive = ($statusTarget['status'] ?? null) === 'active';
        @endphp
        <div class="fixed inset-0 z-[1100] flex items-center justify-center bg-black/50 p-5">
            <div class="bg-white w-full max-w-md p-5 rounded-lg">
                <h3 class="text-lg font-semibold mb-2">
                    {{ $statusIsActive ? __('Deactivate this boundary?') : __('Activate this boundary?') }}
                </h3>
                <p class="text-sm text-gray-600 mb-4">
                    @if ($statusIsActive)
                        {{ __('Staff will stop receiving ENTER/EXIT events for this fence until it is activated again.') }}
                    @else
                        {{ __('Staff will start receiving ENTER/EXIT events for this fence.') }}
                    @endif
                </p>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="toggleStatusConfirmed" class="{{ $statusIsActive ? 'delete-btn' : 'save-btn' }}">
                        {{ $statusIsActive ? __('Deactivate') : __('Activate') }}
                    </button>
                    <button type="button" wire:click="cancelToggleStatus" class="cancel-btn">{{ __('Cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    @if ($confirmingDeleteId)
        <div class="fixed inset-0 z-[1100] flex items-center justify-center bg-black/50 p-5">
            <div class="bg-white w-full max-w-md p-5 rounded-lg">
                <h3 class="text-lg font-semibold mb-2">{{ __('Delete this boundary?') }}</h3>
                <p class="text-sm text-gray-600 mb-4">
                    {{ __('This will permanently delete the boundary and its ENTER/EXIT event history.') }}
                </p>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="deleteConfirmed" class="delete-btn">{{ __('Delete') }}</button>
                    <button type="button" wire:click="cancelDelete" class="cancel-btn">{{ __('Cancel') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>

@script
<script>
    GeofenceEditor.boot($wire, @json($mapCenter));
    GeofenceEditor.sync(@json($locations), @json($form['id'] ?? $focusedId ?? null));
</script>
@endscript

