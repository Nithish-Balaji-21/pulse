<x-form-section submit="updateTeamName">
    <x-slot name="title">
        {{ __('Factory Details') }}
    </x-slot>

    <x-slot name="description">
        {{ __('The factory\'s branch name and other details.') }}
    </x-slot>

    <x-slot name="form">
        {{-- <!-- factory Owner Information -->
        <div class="col-span-6">
            <x-label value="{{ __('Factory Owner') }}" />

            <div class="flex items-center mt-2">
                <img class="size-12 rounded-full object-cover" src="{{ $team->owner->profile_photo_url }}" alt="{{ $team->owner->name }}">

                <div class="ms-4 leading-tight">
                    <div class="text-gray-900">{{ $team->owner->name }}</div>
                    <div class="text-gray-700 text-sm">{{ $team->owner->email }}</div>
                </div>
            </div>
        </div> --}}

        <!-- Factory Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Factory Name') }}" />

            <x-input id="name"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.name"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="name" class="mt-2" />
        </div>

        <!-- Alpine Initialization for Address Fields and Modules -->
        <div x-data="{
            init() {
                let teamAddress = @js($team->address);
                if (this.$wire.state.address_line_1 === undefined && teamAddress) {
                    this.$wire.state.address_line_1 = teamAddress.address_line_1 || '';
                    this.$wire.state.address_line_2 = teamAddress.address_line_2 || '';
                    this.$wire.state.city = teamAddress.city || '';
                    this.$wire.state.state = teamAddress.state || '';
                    this.$wire.state.pincode = teamAddress.pincode || this.$wire.state.pincode || '';
                }

                let teamModules = @js($team->pulse?->modules['pulse']['modules'] ?? null);
                if (this.$wire.state.has_pith_module === undefined && teamModules) {
                    this.$wire.state.has_pith_module = teamModules.pith?.is_active ?? false;
                    this.$wire.state.has_pith_module_blocks = teamModules.pith?.submodules?.blocks ?? false;
                    this.$wire.state.has_pith_module_bales = teamModules.pith?.submodules?.bales ?? false;
                    this.$wire.state.has_fibre_module = teamModules.fibre?.is_active ?? false;
                    this.$wire.state.has_fibre_module_weight = teamModules.fibre?.submodules?.weight ?? false;
                    this.$wire.state.has_growbags_module = teamModules.growbags?.is_active ?? false;
                    this.$wire.state.has_husks_stock_module = teamModules.husks_stock?.is_active ?? false;
                    this.$wire.state.has_water_meter_module = teamModules.water_meter?.is_active ?? false;
                    this.$wire.state.has_attendance_module = teamModules.attendance?.is_active ?? true;
                    this.$wire.state.has_geofencing_module = teamModules.geofencing?.is_active ?? false;
                    this.$wire.state.geofencing_max_boundaries = teamModules.geofencing?.max_boundaries ?? {{ (int) config('geofencing.default_max_boundaries') }};
                }
            }
        }"></div>

        <!-- Address Line 1 -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="address_line_1" value="{{ __('Address Line 1') }}" />

            <x-input id="address_line_1"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.address_line_1"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="address_line_1" class="mt-2" />
        </div>

        <!-- Address Line 2 -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="address_line_2" value="{{ __('Address Line 2') }}" />

            <x-input id="address_line_2"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.address_line_2"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="address_line_2" class="mt-2" />
        </div>

        <!-- City -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="city" value="{{ __('City') }}" />

            <x-input id="city"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.city"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="city" class="mt-2" />
        </div>

        <!-- State -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="state" value="{{ __('State') }}" />

            <x-input id="state"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.state"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="state" class="mt-2" />
        </div>

        <!-- Pincode -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="pincode" value="{{ __('Pincode') }}" />

            <x-input id="pincode"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.pincode"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="pincode" class="mt-2" />
        </div>

         <!-- Coordinates -->
         <div class="col-span-6 sm:col-span-4">
            <x-label for="coordinates" value="{{ __('Coordinates') }}" />

            <x-input id="coordinates"
                        type="text"
                        class="mt-1 block w-full"
                        wire:model="state.coordinates"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="coordinates" class="mt-2" />
        </div>

        @if (Auth::user()->is_superadmin)
        <!-- License Valid Till -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="license_valid_till" value="{{ __('License Valid Till') }}" />

            <x-input id="license_valid_till"
                        type="date"
                        class="mt-1 block w-full"
                        wire:model="state.license_valid_till"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="license_valid_till" class="mt-2" />
        </div>

        <!-- Is Active -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="is_active" value="{{ __('Is Active') }}" />

            <div class="mt-2">
                <!-- Active Option -->
                <label class="inline-flex items-center">
                    <x-input id="active"
                             type="radio"
                             name="is_active"
                             value="1"
                             class="border-gray-300 focus:border-0 focus:ring-0 rounded-md shadow-xs text-green-500"
                             wire:model="state.is_active"
                             :disabled="! Gate::check('update', $team)" />
                    <span class="ml-2 text-gray-700">{{ __('Active') }}</span>
                </label>

                <!-- Inactive Option -->
                <label class="inline-flex items-center ml-4">
                    <x-input id="inactive"
                             type="radio"
                             name="is_active"
                             value="0"
                             class="border-gray-300 focus:border-0 focus:ring-0 rounded-md shadow-xs text-red-500"
                             wire:model="state.is_active"
                             :disabled="! Gate::check('update', $team)" />
                    <span class="ml-2 text-gray-700">{{ __('Inactive') }}</span>
                </label>
            </div>

            <x-input-error for="state.is_active" class="mt-2" />
        </div>
        @endif

        @if (Auth::user()->is_superadmin || Auth::user()->ownsTeam($team))
        <!-- Modules -->
        <div class="col-span-6 sm:col-span-4 mt-6 pt-6 border-t border-gray-200">
            <h3 class="text-base font-semibold text-gray-900 mb-3 flex items-center gap-2">
                <i class="fa-solid fa-cubes text-indigo-600"></i> Active Main Module & Submodules
            </h3>
            <div class="grid grid-cols-1 gap-4">
                <!-- Pith -->
                <div class="border border-amber-200 rounded-lg p-3 bg-amber-50/40 shadow-xs" x-show="$wire.state.has_pith_module">
                    <label class="flex items-center font-semibold text-amber-900 cursor-pointer">
                        <x-checkbox wire:model="state.has_pith_module" class="mr-3 text-amber-600 focus:ring-amber-500 rounded" :disabled="! Gate::check('update', $team)" />
                        Pith
                    </label>
                    <div class="ml-7 mt-2 flex flex-col gap-2">
                        <label class="flex items-center text-sm font-medium text-gray-700 cursor-pointer hover:text-gray-900">
                            <x-checkbox wire:model="state.has_pith_module_blocks" class="mr-2 text-amber-500 focus:ring-amber-500 rounded" :disabled="! Gate::check('update', $team)" />
                            Pith Blocks
                        </label>
                        <label class="flex items-center text-sm font-medium text-gray-700 cursor-pointer hover:text-gray-900">
                            <x-checkbox wire:model="state.has_pith_module_bales" class="mr-2 text-amber-500 focus:ring-amber-500 rounded" :disabled="! Gate::check('update', $team)" />
                             Fibre Bales
                        </label>
                    </div>
                </div>

                <!-- Fibre -->
                <div class="border border-blue-200 rounded-lg p-3 bg-blue-50/40 shadow-xs" x-show="$wire.state.has_fibre_module">
                    <label class="flex items-center font-semibold text-blue-900 cursor-pointer">
                        <x-checkbox wire:model="state.has_fibre_module" class="mr-3 text-blue-600 focus:ring-blue-500 rounded" :disabled="! Gate::check('update', $team)" />
                        Fibre
                    </label>
                    <div class="ml-7 mt-2 flex flex-col gap-2">
                        <label class="flex items-center text-sm font-medium text-gray-700 cursor-pointer hover:text-gray-900">
                            <x-checkbox wire:model="state.has_fibre_module_weight" class="mr-2 text-blue-500 focus:ring-blue-500 rounded" :disabled="! Gate::check('update', $team)" />
                            Fibre Weight
                        </label>
                    </div>
                </div>

                <!-- Growbags -->
                <div class="border border-green-200 rounded-lg p-3 bg-green-50/40 shadow-xs" x-show="$wire.state.has_growbags_module">
                    <label class="flex items-center font-semibold text-green-900 cursor-not-allowed">
                        <x-checkbox wire:model="state.has_growbags_module" class="mr-3 text-green-600 focus:ring-green-500 rounded" :disabled="true" />
                        Growbags
                    </label>
                </div>

                <!-- Husks Stock -->
                <div class="border border-orange-200 rounded-lg p-3 bg-orange-50/40 shadow-xs" x-show="$wire.state.has_husks_stock_module">
                    <label class="flex items-center font-semibold text-orange-900 cursor-pointer">
                        <x-checkbox wire:model="state.has_husks_stock_module" class="mr-3 text-orange-600 focus:ring-orange-500 rounded" :disabled="! Gate::check('update', $team)" />
                        Husks Stock
                    </label>
                </div>

                <!-- Water Meter -->
                <div class="border border-teal-200 rounded-lg p-3 bg-teal-50/40 shadow-xs" x-show="$wire.state.has_water_meter_module">
                    <label class="flex items-center font-semibold text-teal-900 cursor-pointer">
                        <x-checkbox wire:model="state.has_water_meter_module" class="mr-3 text-teal-600 focus:ring-teal-500 rounded" :disabled="! Gate::check('update', $team)" />
                        Water Meter
                    </label>
                </div>
            </div>
        </div>

        {{--
            Hidden for a device-only factory — fibre and/or growbags without pith. Labour is
            withdrawn for those teams (see Team::getHasAttendanceModuleAttribute), so offering the
            toggle would be offering a switch that does nothing. Keyed off the live form state,
            like every module block above, so it reacts as soon as pith is toggled rather than
            waiting for a save.
        --}}
        <div class="col-span-6 sm:col-span-4 mt-6 pt-6 border-t border-gray-200">
            <h3 class="text-base font-semibold text-gray-900 mb-3 flex items-center gap-2">
                <i class="fa-solid fa-puzzle-piece text-emerald-600"></i> Add-on Modules
            </h3>
            <div class="grid grid-cols-1 gap-4">
                <div class="border border-violet-200 rounded-lg p-3 bg-violet-50/50 shadow-xs">
                    <label class="flex items-center font-semibold text-gray-800 cursor-pointer">
                        <x-checkbox wire:model="state.has_geofencing_module" class="mr-3 text-violet-600 focus:ring-violet-500 rounded" :disabled="! Gate::check('update', $team)" />
                        <i class="fa-solid fa-draw-polygon text-violet-600 text-base mr-2"></i> Geofencing
                    </label>
                    <div class="ml-7 mt-2" x-show="$wire.state.has_geofencing_module">
                        <label class="block caps-label text-gray-600 mb-1">Max boundaries</label>
                        <x-input type="number" min="1" :max="config('geofencing.absolute_max_boundaries')" wire:model="state.geofencing_max_boundaries" class="mt-1 block w-40" :disabled="! Gate::check('update', $team)" />
                        <x-input-error for="geofencing_max_boundaries" class="mt-1" />
                    </div>
                </div>
            </div>
        </div>

        {{--
            Hidden for a device-only factory — fibre and/or growbags without pith. Labour is
            withdrawn for those teams (see Team::getHasAttendanceModuleAttribute), so offering the
            toggle would be offering a switch that does nothing. Keyed off the live form state,
            like every module block above, so it reacts as soon as pith is toggled rather than
            waiting for a save.
        --}}
        <div class="col-span-6 sm:col-span-4 mt-6 pt-6 border-t border-gray-200"
            x-show="! (($wire.state.has_fibre_module || $wire.state.has_growbags_module) && ! $wire.state.has_pith_module)">
            <h3 class="text-base font-semibold text-gray-900 mb-3 flex items-center gap-2">
                <i class="fa-solid fa-puzzle-piece text-emerald-600"></i> Attendance
            </h3>
            <div class="grid grid-cols-1 gap-4">
                <!-- Attendance -->
                <div class="border border-emerald-200 rounded-lg p-3 bg-emerald-50/50 shadow-xs">
                    <label class="flex items-center font-semibold text-gray-800 cursor-pointer">
                        <x-checkbox wire:model="state.has_attendance_module" class="mr-3 text-emerald-600 focus:ring-emerald-500 rounded" :disabled="! Gate::check('update', $team)" />
                        <i class="fa-solid fa-users-gear text-emerald-600 text-base mr-2"></i> Staff Attendance
                    </label>
                </div>
            </div>
        </div>
        @endif

    </x-slot>

    @if (Gate::check('update', $team))
        <x-slot name="actions">
            <x-action-message class="me-3" on="saved">
                {{ __('Saved.') }}
            </x-action-message>

            <x-button>
                {{ __('Save') }}
            </x-button>
        </x-slot>
    @endif
</x-form-section>
