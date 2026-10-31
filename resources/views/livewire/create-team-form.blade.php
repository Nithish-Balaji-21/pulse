<div>
    <div class="factory-wizard">
        <!-- Sidebar -->
        <div class="factory-wizard-sidebar">
            <h2>Create Factory</h2>
            <p class="subtext">Factory Setup</p>

            <nav class="flex-1 space-y-3">
                @foreach($this->steps as $idx => $step)
                    <button type="button" wire:click="setStep({{ $idx }})" class="flex items-center gap-2.5 w-full text-left group">
                        <div class="
                            @if($idx < $currentStep) step-completed
                            @elseif($idx == $currentStep) step-active
                            @else step-pending
                            @endif">
                            <div class="step-circle transition-all">
                                @if($idx < $currentStep)
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                @else
                                    {{ $idx }}
                                @endif
                            </div>
                        </div>
                        <p class="font-semibold text-xs transition-colors group-hover:text-[var(--rust)]
                            @if($idx > $currentStep) text-[var(--brown)] @endif">
                            {{ $step['title'] }}
                        </p>
                    </button>
                @endforeach
            </nav>
        </div>

        <!-- Content Pane -->
        <div class="factory-wizard-content">
            <div class="factory-wizard-body">
                @php
                    $stepInfo = $this->steps[$currentStep];
                    $totalSteps = count($this->steps);
                @endphp
                <p class="caps-label mb-0.5" style="color: var(--amber);">Step {{ $currentStep }} of {{ $totalSteps }}</p>
                <h3 class="text-lg font-semibold mb-0.5">{{ $stepInfo['title'] }}</h3>
                <p class="text-xs text-[var(--brown)] mb-5">{{ $stepInfo['subtitle'] }}</p>

                @if($errors->any())
                    <div class="wizard-error-box" role="alert">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- ═══════ STEP 1: Factory & Product ═══════ --}}
                @if($stepInfo['title'] === 'Factory & Product')
                    <div class="space-y-6">
                        <div class="wiz-grid">
                            <div class="col-6">
                                <label class="wizard-label">Factory name <span class="wizard-required">*</span></label>
                                <div class="input-wrapper has-left-icon">
                                    <i class="input-icon-left fa-solid fa-industry"></i>
                                    <input type="text" wire:model.live.debounce.500ms="state.name" class="input-field @error('state.name') is-invalid @enderror" placeholder="e.g. BA Coirs" autocomplete="do-not-autofill">
                                    @error('state.name')
                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                    @else
                                        @if(!empty($state['name']))
                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                        @endif
                                    @enderror
                                </div>
                                @error('state.name')
                                    <span class="wiz-error-text">{{ $message }}</span>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <label class="wizard-label mb-3">Choose Products <span class="wizard-required">*</span></label>
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-start">
                                <!-- Pulse Card -->
                                <div class="toggle-card md:col-span-6 {{ $state['pulse_enabled'] ? 'active' : '' }}">
                                    <div class="flex items-start gap-4" wire:click="$toggle('state.pulse_enabled')">
                                        <div class="wiz-checkbox-box {{ $state['pulse_enabled'] ? 'checked' : '' }}">
                                            @if($state['pulse_enabled'])
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                            @endif
                                        </div>
                                        <div class="flex gap-3 items-start w-full">
                                            <div class="product-icon-badge">
                                                <i class="fa-solid fa-heart-pulse"></i>
                                            </div>
                                            <div>
                                                <h4 class="font-semibold text-base mb-1">Pulse</h4>
                                                <p class="text-xs text-[var(--brown)] mb-2">IoT & production monitoring for the factory floor.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="toggle-card-expand">
                                        @if($state['pulse_enabled'])
                                        <div class="mt-4 pt-4 border-t border-[var(--line)]">
                                            <div class="wiz-grid">
                                                <div class="col-12">
                                                    <label class="wizard-label text-xs">License valid till <span class="wizard-required">*</span></label>
                                                    <div class="input-wrapper has-left-icon">
                                                        <i class="input-icon-left fa-solid fa-calendar-days"></i>
                                                        <input type="date" wire:key="pulse_license_expiry_input" wire:model.live="state.pulse_license_expiry" class="input-field text-sm @error('state.pulse_license_expiry') is-invalid @enderror">
                                                        @error('state.pulse_license_expiry')
                                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                        @else
                                                            @if(!empty($state['pulse_license_expiry']))
                                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                            @endif
                                                        @enderror
                                                    </div>
                                                    @error('state.pulse_license_expiry')
                                                        <span class="wiz-error-text">{{ $message }}</span>
                                                    @enderror
                                                </div>
                                                <div class="col-6">
                                                    <label class="wizard-label text-xs">Device ID <span class="wizard-required">*</span></label>
                                                    <div class="input-wrapper has-left-icon">
                                                        <i class="input-icon-left fa-solid fa-tag"></i>
                                                        <input type="text" wire:model.live.debounce.500ms="state.device_id" class="input-field text-sm @error('state.device_id') is-invalid @enderror" placeholder="Device ID">
                                                        @error('state.device_id')
                                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                        @else
                                                            @if(!empty($state['device_id']))
                                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                            @endif
                                                        @enderror
                                                    </div>
                                                    @error('state.device_id')
                                                        <span class="wiz-error-text">{{ $message }}</span>
                                                    @enderror
                                                </div>
                                                <div class="col-6">
                                                    <label class="wizard-label text-xs whitespace-nowrap">Price per EB unit (₹) <span class="wizard-required">*</span></label>
                                                    <div class="input-wrapper has-left-icon">
                                                        <i class="input-icon-left fa-solid fa-indian-rupee-sign"></i>
                                                        <input type="number" step="0.01" wire:model.live.debounce.500ms="state.eb_units" class="input-field text-sm @error('state.eb_units') is-invalid @enderror" placeholder="0.00">
                                                        @error('state.eb_units')
                                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                        @else
                                                            @if(!empty($state['eb_units']))
                                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                            @endif
                                                        @enderror
                                                    </div>
                                                    @error('state.eb_units')
                                                        <span class="wiz-error-text">{{ $message }}</span>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                        @endif
                                    </div>
                                </div>

                            </div>
                            @error('products')
                                <p class="wiz-error-text mt-2"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif

                {{-- ═══════ STEP 2: Pulse Configuration ═══════ --}}
                @if($stepInfo['title'] === 'Pulse Configuration')
                    <div class="space-y-5">
                        <div>
                            <label class="wizard-label mb-1.5">Pulse Module Picker <span class="wizard-required">*</span></label>
                            <div class="grid grid-cols-3 gap-2.5" style="max-width: 480px;">
                                @foreach(['pith' => 'Pith CoiioT', 'fibre' => 'Fibre CoiioT', 'growbags' => 'Growbags CoiioT'] as $val => $label)
                                    <button type="button" wire:click="$set('state.module', '{{ $val }}')" class="wizard-module-option {{ $state['module'] === $val ? 'active' : '' }}">
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        @if($state['module'])
                            <!-- Submodules -->
                            <div class="p-3 bg-[var(--paper)] rounded-lg">
                                <h4 class="wizard-section-title" style="margin-top:0;">Submodules</h4>
                                @if($state['module'] === 'pith')
                                     <div class="space-y-2.5" wire:key="pith_submodules" x-data="{
                                         fibreBalesExpanded: false
                                     }" x-effect="fibreBalesExpanded = $wire.state.has_pith_bales">
                                         <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                                             <!-- Pith Blocks Card -->
                                             <div class="submodule-card {{ $state['has_pith_blocks'] ? 'active' : '' }}" wire:click.stop="$toggle('state.has_pith_blocks')">
                                                 <input type="checkbox" @if($state['has_pith_blocks']) checked @endif class="rounded text-[var(--rust)] flex-shrink-0">
                                                 <div class="submodule-icon-badge">
                                                     <i class="fa-solid fa-cubes"></i>
                                                 </div>
                                                 <span class="text-sm font-semibold">Pith Blocks</span>
                                             </div>

                                             <!-- Fibre Bales Card -->
                                             <div class="submodule-card {{ $state['has_pith_bales'] ? 'active' : '' }}" wire:click.stop="$toggle('state.has_pith_bales')">
                                                 <div class="flex items-center gap-3 flex-1">
                                                     <input type="checkbox" @if($state['has_pith_bales']) checked @endif class="rounded text-[var(--rust)] flex-shrink-0">
                                                     <div class="submodule-icon-badge">
                                                         <i class="fa-solid fa-boxes-stacked"></i>
                                                     </div>
                                                     <span class="text-sm font-semibold">Fibre Bales</span>
                                                 </div>
                                                 @if($state['has_pith_bales'])
                                                     <i class="addon-chevron fa-solid fa-chevron-down" :class="fibreBalesExpanded ? 'fa-chevron-up' : 'fa-chevron-down'" @click.stop="fibreBalesExpanded = !fibreBalesExpanded"></i>
                                                 @endif
                                             </div>
                                         </div>

                                         <!-- Fibre Bales Details Pane -->
                                         <div class="addon-details-pane {{ $state['has_pith_bales'] ? '' : 'hidden' }}" x-show="fibreBalesExpanded" x-cloak style="margin-top: 1rem; padding: 1rem; border: 1.5px solid var(--line); border-radius: 0.5rem; background-color: var(--white);" @click.stop="">
                                             <div class="wiz-grid mb-3">
                                                 <div class="col-4">
                                                     <label class="wizard-label text-xs uppercase">Number of machines</label>
                                                     <div class="input-wrapper has-left-icon">
                                                         <i class="input-icon-left fa-solid fa-calculator"></i>
                                                         <input type="number" min="1" max="10" wire:model.live="state.pith_bales_count" class="input-field text-sm">
                                                     </div>
                                                 </div>
                                             </div>

                                             <div class="space-y-3">
                                                 @foreach($state['pith_bales_machines'] as $index => $machine)
                                                     <div wire:key="pith_bale_{{ $index }}" class="p-3 bg-gray-50 border border-gray-100 rounded-md">
                                                         <h5 class="font-semibold text-xs text-[var(--brown)] mb-2">Machine {{ $index + 1 }}</h5>
                                                         <div class="wiz-grid">
                                                             <div class="col-6">
                                                                 <label class="wizard-label text-xs uppercase">Field ID</label>
                                                                 <div class="input-wrapper has-left-icon">
                                                                     <i class="input-icon-left fa-solid fa-tag"></i>
                                                                     <input type="text" wire:model.blur="state.pith_bales_machines.{{ $index }}.field_id" class="input-field text-sm @error('state.pith_bales_machines.'.$index.'.field_id') is-invalid @enderror" placeholder="e.g. D40">
                                                                     @error('state.pith_bales_machines.'.$index.'.field_id')
                                                                         <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                     @else
                                                                         @if(!empty($machine['field_id']))
                                                                             <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                         @endif
                                                                     @enderror
                                                                 </div>
                                                                 @error('state.pith_bales_machines.'.$index.'.field_id')
                                                                     <span class="wiz-error-text">{{ $message }}</span>
                                                                 @enderror
                                                             </div>
                                                             <div class="col-6">
                                                                 <label class="wizard-label text-xs uppercase">Machine Name</label>
                                                                 <div class="input-wrapper has-left-icon">
                                                                     <i class="input-icon-left fa-solid fa-file-signature"></i>
                                                                     <input type="text" wire:model.blur="state.pith_bales_machines.{{ $index }}.name" class="input-field text-sm @error('state.pith_bales_machines.'.$index.'.name') is-invalid @enderror" placeholder="e.g. M1">
                                                                     @error('state.pith_bales_machines.'.$index.'.name')
                                                                         <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                     @else
                                                                         @if(!empty($machine['name']))
                                                                             <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                         @endif
                                                                     @enderror
                                                                 </div>
                                                                 @error('state.pith_bales_machines.'.$index.'.name')
                                                                     <span class="wiz-error-text">{{ $message }}</span>
                                                                 @enderror
                                                             </div>
                                                         </div>
                                                     </div>
                                                 @endforeach
                                             </div>
                                         </div>
                                     </div>
                                @elseif($state['module'] === 'fibre')
                                    <div class="space-y-2">
                                        <div class="submodule-card {{ $state['has_fibre_weight'] ? 'active' : '' }}" wire:click.stop="$toggle('state.has_fibre_weight')">
                                            <input type="checkbox" @if($state['has_fibre_weight']) checked @endif class="rounded text-[var(--rust)] flex-shrink-0">
                                            <div class="submodule-icon-badge">
                                                <i class="fa-solid fa-weight-scale"></i>
                                            </div>
                                            <span class="text-sm font-semibold">Fibre Weight</span>
                                        </div>
                                    </div>
                                @elseif($state['module'] === 'growbags')
                                    <div class="space-y-3" x-data="{ growbagsExpanded: true }">
                                        <div class="addon-card active flex items-center justify-between">
                                            <div class="addon-card-left">
                                                <div class="addon-icon-badge">
                                                    <i class="fa-solid fa-seedling"></i>
                                                </div>
                                                <span class="text-sm font-semibold">Growbags Machines</span>
                                            </div>
                                            <i class="addon-chevron fa-solid fa-chevron-up" :class="growbagsExpanded ? 'fa-chevron-up' : 'fa-chevron-down'" @click.stop="growbagsExpanded = !growbagsExpanded"></i>
                                        </div>

                                        <div class="addon-details-pane" x-show="growbagsExpanded">
                                            <div class="wiz-grid mb-3">
                                                <div class="col-4">
                                                    <label class="wizard-label text-xs uppercase">Number of machines</label>
                                                    <div class="input-wrapper has-left-icon">
                                                        <i class="input-icon-left fa-solid fa-calculator"></i>
                                                        <input type="number" min="1" max="10" wire:model.live="state.growbags_count" class="input-field text-sm">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="space-y-3">
                                                @foreach($state['growbags_machines'] as $index => $machine)
                                                    <div wire:key="growbag_{{ $index }}" class="p-3 bg-gray-50 border border-gray-100 rounded-md">
                                                        <h5 class="font-semibold text-xs text-[var(--brown)] mb-2">Machine {{ $index + 1 }}</h5>
                                                        <div class="wiz-grid">
                                                            <div class="col-4">
                                                                <label class="wizard-label text-xs uppercase">Field ID</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-tag"></i>
                                                                    <input type="text" wire:model.blur="state.growbags_machines.{{ $index }}.field_id" class="input-field text-sm @error('state.growbags_machines.'.$index.'.field_id') is-invalid @enderror" placeholder="e.g. D40">
                                                                    @error('state.growbags_machines.'.$index.'.field_id')
                                                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                    @else
                                                                        @if(!empty($machine['field_id']))
                                                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                        @endif
                                                                    @enderror
                                                                </div>
                                                                @error('state.growbags_machines.'.$index.'.field_id')
                                                                    <span class="wiz-error-text">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                            <div class="col-4">
                                                                <label class="wizard-label text-xs uppercase">Machine Name</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-file-signature"></i>
                                                                    <input type="text" wire:model.blur="state.growbags_machines.{{ $index }}.name" class="input-field text-sm @error('state.growbags_machines.'.$index.'.name') is-invalid @enderror" placeholder="e.g. M1">
                                                                    @error('state.growbags_machines.'.$index.'.name')
                                                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                    @else
                                                                        @if(!empty($machine['name']))
                                                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                        @endif
                                                                    @enderror
                                                                </div>
                                                                @error('state.growbags_machines.'.$index.'.name')
                                                                    <span class="wiz-error-text">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                            <div class="col-4">
                                                                <label class="wizard-label text-xs uppercase">Bags per punch</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-bag-shopping"></i>
                                                                    <input type="number" wire:model.blur="state.growbags_machines.{{ $index }}.bags" class="input-field text-sm @error('state.growbags_machines.'.$index.'.bags') is-invalid @enderror" placeholder="e.g. 5">
                                                                    @error('state.growbags_machines.'.$index.'.bags')
                                                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                    @else
                                                                        @if(!empty($machine['bags']))
                                                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                        @endif
                                                                    @enderror
                                                                </div>
                                                                @error('state.growbags_machines.'.$index.'.bags')
                                                                    <span class="wiz-error-text">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <!-- Add-on Modules -->
                            <div>
                                <h4 class="wizard-section-title">Add-on Modules</h4>
                                <div class="addon-modules-stack">
                                    <!-- Attendance -->
                                    <div class="addon-card {{ $state['attendance_enabled'] ? 'active' : '' }}" wire:click.stop="$toggle('state.attendance_enabled')">
                                        <div class="addon-card-header">
                                            <div class="addon-card-left">
                                                <input type="checkbox" @if($state['attendance_enabled']) checked @endif class="rounded text-[var(--rust)] flex-shrink-0">
                                                <div class="addon-icon-badge">
                                                    <i class="fa-solid fa-user-check"></i>
                                                </div>
                                                <span class="text-sm font-semibold">Attendance</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Geofencing -->
                                    <div class="addon-card {{ $state['geofencing_enabled'] ? 'active' : '' }}" x-data="{ geofencingExpanded: false }" x-effect="geofencingExpanded = $wire.state.geofencing_enabled" wire:click.stop="$toggle('state.geofencing_enabled')">
                                        <div class="addon-card-header">
                                            <div class="addon-card-left">
                                                <input type="checkbox" @if($state['geofencing_enabled']) checked @endif class="rounded text-[var(--rust)] flex-shrink-0">
                                                <div class="addon-icon-badge">
                                                    <i class="fa-solid fa-draw-polygon"></i>
                                                </div>
                                                <span class="text-sm font-semibold">Geofencing</span>
                                            </div>
                                            @if($state['geofencing_enabled'])
                                                <i class="addon-chevron fa-solid fa-chevron-down" :class="geofencingExpanded ? 'fa-chevron-up' : 'fa-chevron-down'" @click.stop="geofencingExpanded = !geofencingExpanded"></i>
                                            @endif
                                        </div>
                                        <div class="addon-details-pane {{ $state['geofencing_enabled'] ? '' : 'hidden' }}" x-show="geofencingExpanded" @click.stop="">
                                            <div class="wiz-grid">
                                                <div class="col-12">
                                                    <label class="wizard-label text-xs uppercase">Max boundaries</label>
                                                    <div class="input-wrapper has-left-icon">
                                                        <i class="input-icon-left fa-solid fa-border-all"></i>
                                                        <input type="number" min="1" max="{{ config('geofencing.absolute_max_boundaries') }}" wire:model.live="state.geofencing_max_boundaries" class="input-field text-sm @error('state.geofencing_max_boundaries') is-invalid @enderror">
                                                    </div>
                                                    @error('state.geofencing_max_boundaries')
                                                        <span class="wiz-error-text">{{ $message }}</span>
                                                    @enderror
                                                    <p class="text-xs text-[var(--brown)] mt-1">Owner/SuperAdmin can change this later. Default is {{ config('geofencing.default_max_boundaries') }}.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Water Consumed -->
                                    <div class="addon-card {{ $state['has_water_meter'] ? 'active' : '' }}" x-data="{
                                        waterMeterExpanded: false
                                    }" x-effect="waterMeterExpanded = $wire.state.has_water_meter" wire:click.stop="$toggle('state.has_water_meter')">
                                        <div class="addon-card-header">
                                            <div class="addon-card-left">
                                                <input type="checkbox" @if($state['has_water_meter']) checked @endif class="rounded text-[var(--rust)] flex-shrink-0">
                                                <div class="addon-icon-badge">
                                                    <i class="fa-solid fa-droplet"></i>
                                                </div>
                                                <span class="text-sm font-semibold">Water Consumed</span>
                                            </div>
                                            @if($state['has_water_meter'])
                                                <i class="addon-chevron fa-solid fa-chevron-down" :class="waterMeterExpanded ? 'fa-chevron-up' : 'fa-chevron-down'" @click.stop="waterMeterExpanded = !waterMeterExpanded"></i>
                                            @endif
                                        </div>

                                        <div class="addon-details-pane {{ $state['has_water_meter'] ? '' : 'hidden' }}" x-show="waterMeterExpanded" @click.stop="">
                                            <div class="wiz-grid mb-3">
                                                <div class="col-12">
                                                    <label class="wizard-label text-xs uppercase">Number of machines</label>
                                                    <div class="input-wrapper has-left-icon">
                                                        <i class="input-icon-left fa-solid fa-calculator"></i>
                                                        <input type="number" min="1" max="10" wire:model.live="state.water_meters_count" class="input-field text-sm">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="space-y-3">
                                                @foreach($state['water_meters'] as $index => $meter)
                                                    <div wire:key="water_meter_{{ $index }}" class="p-3 bg-gray-50 border border-gray-100 rounded-md">
                                                        <h5 class="font-semibold text-xs text-[var(--brown)] mb-2">Machine {{ $index + 1 }}</h5>
                                                        <div class="wiz-grid">
                                                            <div class="col-6">
                                                                <label class="wizard-label text-xs uppercase">Field ID</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-circle-info"></i>
                                                                    <select wire:model.live="state.water_meters.{{ $index }}.type" class="input-field text-sm">
                                                                        <option value="d27">Total Water (d27)</option>
                                                                        <option value="d16">Husk Water (d16)</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="wizard-label text-xs uppercase">Machine Name</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-file-signature"></i>
                                                                    <input type="text" wire:model.blur="state.water_meters.{{ $index }}.name" class="input-field text-sm @error('state.water_meters.'.$index.'.name') is-invalid @enderror" placeholder="Name">
                                                                    @error('state.water_meters.'.$index.'.name')
                                                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                    @else
                                                                        @if(!empty($meter['name']))
                                                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                        @endif
                                                                    @enderror
                                                                </div>
                                                                @error('state.water_meters.'.$index.'.name')
                                                                    <span class="wiz-error-text">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Water Tank -->
                                    <div class="addon-card {{ $state['has_water_tank'] ? 'active' : '' }}" x-data="{
                                        waterTankExpanded: false
                                    }" x-effect="waterTankExpanded = $wire.state.has_water_tank" wire:click.stop="$toggle('state.has_water_tank')">
                                        <div class="addon-card-header">
                                            <div class="addon-card-left">
                                                <input type="checkbox" @if($state['has_water_tank']) checked @endif class="rounded text-[var(--rust)] flex-shrink-0">
                                                <div class="addon-icon-badge">
                                                    <i class="fa-solid fa-database"></i>
                                                </div>
                                                <span class="text-sm font-semibold">Water Tank</span>
                                            </div>
                                            @if($state['has_water_tank'])
                                                <i class="addon-chevron fa-solid fa-chevron-down" :class="waterTankExpanded ? 'fa-chevron-up' : 'fa-chevron-down'" @click.stop="waterTankExpanded = !waterTankExpanded"></i>
                                            @endif
                                        </div>

                                        <div class="addon-details-pane {{ $state['has_water_tank'] ? '' : 'hidden' }}" x-show="waterTankExpanded" @click.stop="">
                                            <div class="wiz-grid mb-3">
                                                <div class="col-12">
                                                    <label class="wizard-label text-xs uppercase">Number of machines</label>
                                                    <div class="input-wrapper has-left-icon">
                                                        <i class="input-icon-left fa-solid fa-calculator"></i>
                                                        <input type="number" min="1" max="10" wire:model.live="state.water_tanks_count" class="input-field text-sm">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="space-y-3">
                                                @foreach($state['water_tanks'] as $index => $tank)
                                                    <div wire:key="water_tank_{{ $index }}" class="p-3 bg-gray-50 border border-gray-100 rounded-md">
                                                        <h5 class="font-semibold text-xs text-[var(--brown)] mb-2">Machine {{ $index + 1 }}</h5>
                                                        <div class="wiz-grid">
                                                            <div class="col-12">
                                                                <label class="wizard-label text-xs uppercase">Field ID</label>
                                                                <div class="flex items-center gap-4 py-1.5 px-3 bg-white border rounded-md">
                                                                    <label class="flex items-center gap-1.5 text-xs cursor-pointer"><input type="radio" wire:model.live="state.water_tanks.{{ $index }}.type" value="d29"> Underground</label>
                                                                    <label class="flex items-center gap-1.5 text-xs cursor-pointer"><input type="radio" wire:model.live="state.water_tanks.{{ $index }}.type" value="d30"> Overhead</label>
                                                                </div>
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="wizard-label text-xs uppercase">Machine Name</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-file-signature"></i>
                                                                    <input type="text" wire:model.blur="state.water_tanks.{{ $index }}.name" class="input-field text-sm @error('state.water_tanks.'.$index.'.name') is-invalid @enderror" placeholder="Name">
                                                                    @error('state.water_tanks.'.$index.'.name')
                                                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                    @else
                                                                        @if(!empty($tank['name']))
                                                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                        @endif
                                                                    @enderror
                                                                </div>
                                                                @error('state.water_tanks.'.$index.'.name')
                                                                    <span class="wiz-error-text">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="wizard-label text-xs uppercase">Capacity (L)</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-glass-water"></i>
                                                                    <input type="number" wire:model.blur="state.water_tanks.{{ $index }}.capacity" class="input-field text-sm @error('state.water_tanks.'.$index.'.capacity') is-invalid @enderror" placeholder="{{ ($tank['type'] ?? 'd29') === 'd29' ? '175000' : '6000' }}">
                                                                    @error('state.water_tanks.'.$index.'.capacity')
                                                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                    @else
                                                                        @if(!empty($tank['capacity']))
                                                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                        @endif
                                                                    @enderror
                                                                </div>
                                                                @error('state.water_tanks.'.$index.'.capacity')
                                                                    <span class="wiz-error-text">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="wizard-label text-xs uppercase">Low alert %</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-bell"></i>
                                                                    <input type="number" wire:model.blur="state.water_tanks.{{ $index }}.alert_low" class="input-field text-sm @error('state.water_tanks.'.$index.'.alert_low') is-invalid @enderror" placeholder="5">
                                                                    @error('state.water_tanks.'.$index.'.alert_low')
                                                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                    @else
                                                                        @if(!empty($tank['alert_low']))
                                                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                        @endif
                                                                    @enderror
                                                                </div>
                                                                @error('state.water_tanks.'.$index.'.alert_low')
                                                                    <span class="wiz-error-text">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="wizard-label text-xs uppercase">Overflow %</label>
                                                                <div class="input-wrapper has-left-icon">
                                                                    <i class="input-icon-left fa-solid fa-bell-slash"></i>
                                                                    <input type="number" wire:model.blur="state.water_tanks.{{ $index }}.alert_high" class="input-field text-sm @error('state.water_tanks.'.$index.'.alert_high') is-invalid @enderror" placeholder="98">
                                                                    @error('state.water_tanks.'.$index.'.alert_high')
                                                                        <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                                                    @else
                                                                        @if(!empty($tank['alert_high']))
                                                                            <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                                        @endif
                                                                    @enderror
                                                                </div>
                                                                @error('state.water_tanks.'.$index.'.alert_high')
                                                                    <span class="wiz-error-text">{{ $message }}</span>
                                                                @enderror
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Location -->
                            <div>
                                <h4 class="wizard-section-title">Location</h4>
                                <div class="wiz-grid">
                                    <div class="col-8">
                                        <label class="wizard-label">Address Line 1</label>
                                        <div class="input-wrapper has-left-icon">
                                            <i class="input-icon-left fa-solid fa-location-dot"></i>
                                            <input type="text" wire:model.live.debounce.500ms="state.address_line_1" class="input-field" placeholder="Street / Building">
                                        </div>
                                    </div>
                                    <div class="col-8">
                                        <label class="wizard-label">Address Line 2</label>
                                        <div class="input-wrapper has-left-icon">
                                            <i class="input-icon-left fa-solid fa-map-pin"></i>
                                            <input type="text" wire:model.live.debounce.500ms="state.address_line_2" class="input-field" placeholder="Suite / Landmark">
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <label class="wizard-label">City</label>
                                        <div class="input-wrapper has-left-icon">
                                            <i class="input-icon-left fa-solid fa-city"></i>
                                            <input type="text" wire:model.live.debounce.500ms="state.city" class="input-field" placeholder="City">
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <label class="wizard-label">Pincode</label>
                                        <div class="input-wrapper has-left-icon">
                                            <i class="input-icon-left fa-solid fa-hashtag"></i>
                                            <input type="text" wire:model.live.debounce.500ms="state.pincode" class="input-field" placeholder="Pincode">
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <label class="wizard-label">State</label>
                                        <div class="input-wrapper has-left-icon">
                                            <i class="input-icon-left fa-solid fa-map"></i>
                                            <select wire:model.live="state.state" class="input-field">
                                                <option value="">Select State</option>
                                                <option value="Tamil Nadu">Tamil Nadu</option>
                                                <option value="Kerala">Kerala</option>
                                                <option value="Karnataka">Karnataka</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="wizard-label">Coordinates <span class="wizard-required">*</span></label>
                                        <div class="input-wrapper has-left-icon">
                                            <i class="input-icon-left fa-solid fa-location-crosshairs"></i>
                                            <input type="text" wire:model.live.debounce.500ms="state.coordinates" class="input-field @error('state.coordinates') is-invalid @enderror" placeholder="e.g. 11.33233, 77.12345">
                                            @error('state.coordinates')
                                                <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                            @else
                                                @if(!empty($state['coordinates']))
                                                    <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                                @endif
                                            @enderror
                                        </div>
                                        @error('state.coordinates')
                                            <span class="wiz-error-text">{{ $message }}</span>
                                        @enderror
                                    </div>

                                </div>
                            </div>

                            <!-- Shifts -->
                            <div>
                                <h4 class="wizard-section-title">Shifts</h4>
                                <div class="wiz-grid">
                                    <div class="col-4">
                                        <label class="wizard-label text-xs">Number of shifts</label>
                                        <div class="input-wrapper has-left-icon">
                                            <i class="input-icon-left fa-solid fa-calculator"></i>
                                            <select wire:model.live="state.shift_count" class="input-field">
                                                <option value="1">1</option>
                                                <option value="2">2</option>
                                                <option value="3">3</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <label class="wizard-label text-xs">Start time</label>
                                        <input type="time" wire:model.live="state.shift_start" class="input-field" style="max-width: 105px; padding: 0.55rem 0.5rem;">
                                    </div>
                                </div>
                                <div class="mt-2.5 flex flex-col gap-2" style="max-width: 520px;">
                                    @foreach($state['shifts'] as $i => $shift)
                                        <div class="flex items-center gap-2.5 bg-gray-50 py-1.5 px-2.5 border rounded text-xs">
                                            <span class="font-semibold w-16">{{ $shift['name'] }}</span>
                                            <input type="time" wire:model="state.shifts.{{ $i }}.start" class="input-field !py-1 text-xs" style="max-width: 105px; padding: 0.25rem 0.5rem;">
                                            <span>to</span>
                                            <input type="time" wire:model="state.shifts.{{ $i }}.end" class="input-field !py-1 text-xs" style="max-width: 105px; padding: 0.25rem 0.5rem;">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <p class="text-center py-5 border-2 border-dashed border-[var(--line)] text-[var(--brown)] text-sm font-medium rounded">Pick a module above to continue setup.</p>
                        @endif
                    </div>
                @endif

                {{-- ═══════ STEP 3: Pulse Settings ═══════ --}}
                @if($stepInfo['title'] === 'Pulse Settings')
                    <div class="space-y-5">
                        @if($state['module'] === 'pith')
                            <h4 class="wizard-section-title" style="margin-top:0;">Device Thresholds</h4>
                            <div class="wiz-grid">
                                <div class="col-4">
                                    <label class="wizard-label">Off threshold</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-gauge-simple"></i>
                                        <input type="number" wire:model.live.debounce.500ms="state.threshold_off" class="input-field @error('state.threshold_off') is-invalid @enderror">
                                        @error('state.threshold_off')
                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                        @else
                                            @if(!empty($state['threshold_off']))
                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                            @endif
                                        @enderror
                                    </div>
                                    @error('state.threshold_off')
                                        <span class="wiz-error-text">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="col-4">
                                    <label class="wizard-label">Underloaded threshold</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-gauge-simple-high"></i>
                                        <input type="number" wire:model.live.debounce.500ms="state.threshold_underloaded" class="input-field @error('state.threshold_underloaded') is-invalid @enderror">
                                        @error('state.threshold_underloaded')
                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                        @else
                                            @if(!empty($state['threshold_underloaded']))
                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                            @endif
                                        @enderror
                                    </div>
                                    @error('state.threshold_underloaded')
                                        <span class="wiz-error-text">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="col-4">
                                    <label class="wizard-label">Efficient threshold</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-gauge-high"></i>
                                        <input type="number" wire:model.live.debounce.500ms="state.threshold_efficient" class="input-field @error('state.threshold_efficient') is-invalid @enderror">
                                        @error('state.threshold_efficient')
                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                        @else
                                            @if(!empty($state['threshold_efficient']))
                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                            @endif
                                        @enderror
                                    </div>
                                    @error('state.threshold_efficient')
                                        <span class="wiz-error-text">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="col-4">
                                    <label class="wizard-label">Message interval (s)</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-clock"></i>
                                        <input type="number" wire:model.live.debounce.500ms="state.message_interval" class="input-field @error('state.message_interval') is-invalid @enderror">
                                        @error('state.message_interval')
                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                        @else
                                            @if(!empty($state['message_interval']))
                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                            @endif
                                        @enderror
                                    </div>
                                    @error('state.message_interval')
                                        <span class="wiz-error-text">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="col-4">
                                    <label class="wizard-label">Missing check duration (s)</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-hourglass-half"></i>
                                        <input type="number" wire:model.live.debounce.500ms="state.missing_check_duration" class="input-field @error('state.missing_check_duration') is-invalid @enderror">
                                        @error('state.missing_check_duration')
                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                        @else
                                            @if(!empty($state['missing_check_duration']))
                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                            @endif
                                        @enderror
                                    </div>
                                    @error('state.missing_check_duration')
                                        <span class="wiz-error-text">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>

                            <h4 class="wizard-section-title">Customization</h4>
                            <div class="wiz-grid">
                                <div class="col-4">
                                    <label class="wizard-label">EB units per CFT</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-bolt"></i>
                                        <input type="number" step="0.01" wire:model.live.debounce.500ms="state.eb_per_cft" class="input-field @error('state.eb_per_cft') is-invalid @enderror">
                                        @error('state.eb_per_cft')
                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                        @else
                                            @if(!empty($state['eb_per_cft']))
                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                            @endif
                                        @enderror
                                    </div>
                                    @error('state.eb_per_cft')
                                        <span class="wiz-error-text">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="col-4">
                                    <label class="wizard-label">Husks per CFT</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-leaf"></i>
                                        <input type="number" step="0.01" wire:model.live.debounce.500ms="state.husks_per_cft" class="input-field @error('state.husks_per_cft') is-invalid @enderror">
                                        @error('state.husks_per_cft')
                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                        @else
                                            @if(!empty($state['husks_per_cft']))
                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                            @endif
                                        @enderror
                                    </div>
                                    @error('state.husks_per_cft')
                                        <span class="wiz-error-text">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="col-4">
                                    <label class="wizard-label">Default production unit</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-weight-scale"></i>
                                        <input type="text" wire:model="state.default_production_unit" class="input-field" disabled>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <label class="wizard-label mb-1">Prod hours mandatory?</label>
                                    <label class="wizard-switch">
                                        <input type="checkbox" wire:click="$toggle('state.production_hours_mandatory')" @if($state['production_hours_mandatory']) checked @endif>
                                        <span class="wizard-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <h4 class="wizard-section-title">Production Edit Windows</h4>
                            <div class="wiz-grid">
                                @if($state['has_pith_blocks'])
                                <div class="col-4">
                                    <label class="wizard-label">Blocks edit window</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-calendar-days"></i>
                                        <select wire:model="state.blocks_entry_days" class="input-field">
                                            @foreach(\App\Support\Pulse\ProductionEntryPolicyKeys::dayOptionsList() as $d)
                                                <option value="{{ $d }}">{{ $d }} {{ $d == 1 ? 'day' : 'days' }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                @endif
                                <div class="col-4">
                                    <label class="wizard-label">Loads edit window</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-calendar-days"></i>
                                        <select wire:model="state.loads_entry_days" class="input-field">
                                            @foreach(\App\Support\Pulse\ProductionEntryPolicyKeys::dayOptionsList() as $d)
                                                <option value="{{ $d }}">{{ $d }} {{ $d == 1 ? 'day' : 'days' }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                @if($state['has_pith_bales'])
                                <div class="col-4">
                                    <label class="wizard-label">Bales edit window</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-calendar-days"></i>
                                        <select wire:model="state.bales_entry_days" class="input-field">
                                            @foreach(\App\Support\Pulse\ProductionEntryPolicyKeys::dayOptionsList() as $d)
                                                <option value="{{ $d }}">{{ $d }} {{ $d == 1 ? 'day' : 'days' }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                @endif
                            </div>
                        @elseif(in_array($state['module'], ['fibre', 'growbags']))
                            <h4 class="wizard-section-title" style="margin-top:0;">Customization</h4>
                            <div class="wiz-grid">
                                <div class="col-4">
                                    <label class="wizard-label">Less weight alert (kg)</label>
                                    <div class="input-wrapper has-left-icon">
                                        <i class="input-icon-left fa-solid fa-weight-hanging"></i>
                                        <input type="number" wire:model.live.debounce.500ms="state.less_weight_alert" class="input-field @error('state.less_weight_alert') is-invalid @enderror">
                                        @error('state.less_weight_alert')
                                            <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                        @else
                                            @if(!empty($state['less_weight_alert']))
                                                <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                            @endif
                                        @enderror
                                    </div>
                                    @error('state.less_weight_alert')
                                        <span class="wiz-error-text">{{ $message }}</span>
                                    @enderror
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ═══════ STEP 4: Owner Registration ═══════ --}}
                @if($stepInfo['title'] === 'Owner Registration')
                    <div class="wiz-grid">
                        <div class="col-6">
                            <label class="wizard-label">Owner name <span class="wizard-required">*</span></label>
                            <div class="input-wrapper has-left-icon">
                                <i class="input-icon-left fa-solid fa-user"></i>
                                <input type="text" wire:model.live.debounce.500ms="state.owner_name" class="input-field @error('state.owner_name') is-invalid @enderror" placeholder="Factory Owner">
                                @error('state.owner_name')
                                    <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                @else
                                    @if(!empty($state['owner_name']))
                                        <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                    @endif
                                @enderror
                            </div>
                            @error('state.owner_name')
                                <span class="wiz-error-text">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-4">
                            <label class="wizard-label">WhatsApp number <span class="wizard-required">*</span></label>
                            <div class="input-wrapper has-left-icon">
                                <i class="input-icon-left fa-brands fa-whatsapp"></i>
                                <input type="text" wire:model.live.debounce.500ms="state.owner_whatsapp" class="input-field @error('state.owner_whatsapp') is-invalid @enderror" placeholder="e.g. 9876543210">
                                @error('state.owner_whatsapp')
                                    <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                @else
                                    @if(!empty($state['owner_whatsapp']))
                                        <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                    @endif
                                @enderror
                            </div>
                            @error('state.owner_whatsapp')
                                <span class="wiz-error-text">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-6">
                            <label class="wizard-label">Email address <span class="wizard-required">*</span></label>
                            <div class="input-wrapper has-left-icon">
                                <i class="input-icon-left fa-solid fa-envelope"></i>
                                <input type="email" wire:model.live.debounce.500ms="state.owner_email" class="input-field @error('state.owner_email') is-invalid @enderror" placeholder="owner@example.com">
                                @error('state.owner_email')
                                    <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                @else
                                    @if(!empty($state['owner_email']))
                                        <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                    @endif
                                @enderror
                            </div>
                            @error('state.owner_email')
                                <span class="wiz-error-text">{{ $message }}</span>
                            @enderror
                        </div>
                        <div class="col-4">
                            <label class="wizard-label">Password <span class="wizard-required">*</span></label>
                            <div class="input-wrapper has-left-icon">
                                <i class="input-icon-left fa-solid fa-lock"></i>
                                <input type="text" wire:model.live="state.owner_password" class="input-field @error('state.owner_password') is-invalid @enderror" placeholder="Auto-generated">
                                @error('state.owner_password')
                                    <span class="input-icon-right text-red-600"><i class="fa-solid fa-xmark"></i></span>
                                @else
                                    @if(!empty($state['owner_password']))
                                        <span class="input-icon-right text-green-600"><i class="fa-solid fa-check"></i></span>
                                    @endif
                                @enderror
                            </div>
                            @error('state.owner_password')
                                <span class="wiz-error-text">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                @endif

                {{-- ═══════ STEP 5: Preview & Create ═══════ --}}
                @if($stepInfo['title'] === 'Preview & Create')
                    <div class="space-y-3">
                        {{-- Factory Header Card --}}
                        <div class="wizard-preview-card">
                            <div class="wizard-preview-card-left">
                                <div class="wizard-preview-factory-icon">
                                    <i class="fa-solid fa-industry"></i>
                                </div>
                                <div class="wizard-preview-info">
                                    <div class="wizard-preview-title-row">
                                        <h2 class="wizard-preview-title">{{ $state['name'] ?: '—' }}</h2>
                                        <span class="wizard-preview-badge">
                                            <i class="fa-solid fa-circle-check mr-1 text-xs"></i> Ready to Create
                                        </span>
                                    </div>
                                    <div class="wizard-preview-products">
                                        Products: {{ $state['pulse_enabled'] ? 'Pulse' : '' }}
                                    </div>
                                    <div class="wizard-preview-stats">
                                        @if($state['pulse_enabled'])
                                            <div class="wizard-preview-stat-item">
                                                <i class="fa-solid fa-bolt text-yellow-400"></i>
                                                <div class="wizard-preview-stat-content">
                                                    <span class="wizard-preview-stat-label">EB Cost (₹)</span>
                                                    <span class="wizard-preview-stat-value">{{ $state['eb_units'] }}</span>
                                                </div>
                                            </div>
                                            <div class="wizard-preview-stat-item">
                                                <i class="fa-regular fa-calendar-days"></i>
                                                <div class="wizard-preview-stat-content">
                                                    <span class="wizard-preview-stat-label">Pulse Expiry</span>
                                                    <span class="wizard-preview-stat-value">
                                                        {{ $state['pulse_license_expiry'] ? \Carbon\Carbon::parse($state['pulse_license_expiry'])->format('d M Y') : '—' }}
                                                    </span>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @if($state['pulse_enabled'])
                                <div class="wizard-preview-card-right">
                                    <div class="wizard-preview-right-item">
                                        <div class="wizard-preview-right-icon">
                                            <i class="fa-solid fa-microchip"></i>
                                        </div>
                                        <div class="wizard-preview-right-content">
                                            <span class="wizard-preview-right-label">Device ID</span>
                                            <span class="wizard-preview-right-value">{{ $state['device_id'] ?: '—' }}</span>
                                        </div>
                                    </div>
                                    @if($state['has_water_meter'])
                                        <div class="wizard-preview-right-item">
                                            <div class="wizard-preview-right-icon">
                                                <i class="fa-solid fa-droplet"></i>
                                            </div>
                                            <div class="wizard-preview-right-content">
                                                <span class="wizard-preview-right-label">Water Meter</span>
                                                <span class="wizard-preview-right-value">{{ $state['water_meters_count'] }}</span>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @if($state['pulse_enabled'])
                            {{-- Pulse Configuration --}}
                            <div class="wizard-preview-detail text-xs">
                                <div class="wizard-preview-detail-left">
                                    <div class="wizard-preview-detail-icon-badge">
                                        <i class="fa-solid fa-heart-pulse"></i>
                                    </div>
                                </div>
                                <div class="wizard-preview-detail-right">
                                    <h3 class="wizard-preview-detail-title">Pulse — {{ ucfirst($state['module']) }} Module</h3>
                                    <div class="wizard-preview-detail-grid">
                                        @if($state['module'] === 'pith')
                                            <div class="wizard-preview-detail-item">
                                                <i class="fa-solid fa-cubes"></i>
                                                <span class="wizard-preview-detail-label">Pith Blocks:</span>
                                                <span class="wizard-preview-detail-value">{{ $state['has_pith_blocks'] ? 'Yes' : 'No' }}</span>
                                            </div>
                                            <div class="wizard-preview-detail-item">
                                                <i class="fa-solid fa-boxes-stacked"></i>
                                                <span class="wizard-preview-detail-label">Fibre Bales:</span>
                                                <span class="wizard-preview-detail-value">{{ $state['has_pith_bales'] ? 'Yes' : 'No' }}</span>
                                            </div>
                                        @elseif($state['module'] === 'fibre')
                                            <div class="wizard-preview-detail-item">
                                                <i class="fa-solid fa-weight-scale"></i>
                                                <span class="wizard-preview-detail-label">Fibre Weight:</span>
                                                <span class="wizard-preview-detail-value">{{ ($state['has_fibre_weight'] ?? false) ? 'Yes' : 'No' }}</span>
                                            </div>
                                        @elseif($state['module'] === 'growbags')
                                            <div class="wizard-preview-detail-item">
                                                <i class="fa-solid fa-seedling"></i>
                                                <span class="wizard-preview-detail-label">Growbags:</span>
                                                <span class="wizard-preview-detail-value">Yes</span>
                                            </div>
                                        @endif
                                        <div class="wizard-preview-detail-item">
                                            <i class="fa-solid fa-user-check"></i>
                                            <span class="wizard-preview-detail-label">Attendance:</span>
                                            <span class="wizard-preview-detail-value">{{ ($state['attendance_enabled'] ?? false) ? 'Yes' : 'No' }}</span>
                                        </div>
                                        <div class="wizard-preview-detail-item">
                                            <i class="fa-solid fa-draw-polygon"></i>
                                            <span class="wizard-preview-detail-label">Geofencing:</span>
                                            <span class="wizard-preview-detail-value">{{ ($state['geofencing_enabled'] ?? false) ? ($state['geofencing_max_boundaries'].' max') : 'No' }}</span>
                                        </div>
                                        <div class="wizard-preview-detail-item">
                                            <i class="fa-solid fa-droplet"></i>
                                            <span class="wizard-preview-detail-label">Water Meters:</span>
                                            <span class="wizard-preview-detail-value">{{ ($state['has_water_meter'] ?? false) ? $state['water_meters_count'] : 'No' }}</span>
                                        </div>
                                        <div class="wizard-preview-detail-item">
                                            <i class="fa-solid fa-database"></i>
                                            <span class="wizard-preview-detail-label">Water Tanks:</span>
                                            <span class="wizard-preview-detail-value">{{ ($state['has_water_tank'] ?? false) ? 'Yes' : 'No' }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Location & Shifts --}}
                            <div class="wizard-preview-detail text-xs">
                                <div class="wizard-preview-detail-left">
                                    <div class="wizard-preview-detail-icon-badge">
                                        <i class="fa-solid fa-location-dot"></i>
                                    </div>
                                </div>
                                <div class="wizard-preview-detail-right">
                                    <h3 class="wizard-preview-detail-title">Location & Shifts</h3>
                                    <div class="wizard-preview-detail-grid">
                                        <div class="flex flex-col gap-1.5">
                                            <div class="wizard-preview-detail-item">
                                                <i class="fa-solid fa-location-crosshairs"></i>
                                                <span class="wizard-preview-detail-label">Coordinates:</span>
                                                <span class="wizard-preview-detail-value px-1.5 py-0.5 bg-gray-100 rounded text-gray-700 font-mono text-xs">{{ $state['coordinates'] ?: '—' }}</span>
                                            </div>
                                            <div class="wizard-preview-detail-item">
                                                <i class="fa-solid fa-clock"></i>
                                                <span class="wizard-preview-detail-label">Shifts:</span>
                                                <span class="wizard-preview-detail-value">{{ $state['shift_count'] }}</span>
                                            </div>
                                        </div>
                                        <div class="flex flex-col gap-1.5">
                                            @if(isset($state['shifts'][0]))
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-circle-play text-gray-400"></i>
                                                    <span class="wizard-preview-detail-label">Shift 1:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['shifts'][0]['start'] }} – {{ $state['shifts'][0]['end'] }}</span>
                                                </div>
                                            @endif
                                            @if(isset($state['shifts'][1]))
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-circle-play text-gray-400"></i>
                                                    <span class="wizard-preview-detail-label">Shift 2:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['shifts'][1]['start'] }} – {{ $state['shifts'][1]['end'] }}</span>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="flex flex-col gap-1.5">
                                            @if(isset($state['shifts'][2]))
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-circle-play text-gray-400"></i>
                                                    <span class="wizard-preview-detail-label">Shift 3:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['shifts'][2]['start'] }} – {{ $state['shifts'][2]['end'] }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Thresholds & Customization --}}
                            @if($state['module'] === 'pith')
                                <div class="wizard-preview-detail text-xs">
                                    <div class="wizard-preview-detail-left">
                                        <div class="wizard-preview-detail-icon-badge">
                                            <i class="fa-solid fa-sliders"></i>
                                        </div>
                                    </div>
                                    <div class="wizard-preview-detail-right">
                                        <h3 class="wizard-preview-detail-title">Thresholds & Customization</h3>
                                        <div class="wizard-preview-detail-grid">
                                            <div class="flex flex-col gap-1.5">
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-gauge-simple"></i>
                                                    <span class="wizard-preview-detail-label">Off:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['threshold_off'] }}</span>
                                                </div>
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-envelope"></i>
                                                    <span class="wizard-preview-detail-label">Msg Interval:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['message_interval'] }}s</span>
                                                </div>
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-cubes"></i>
                                                    <span class="wizard-preview-detail-label">Husks/CFT:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['husks_per_cft'] }}</span>
                                                </div>
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-gauge-simple-high"></i>
                                                    <span class="wizard-preview-detail-label">Underloaded:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['threshold_underloaded'] }}</span>
                                                </div>
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-circle-exclamation"></i>
                                                    <span class="wizard-preview-detail-label">Missing Check:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['missing_check_duration'] }}s</span>
                                                </div>
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-box"></i>
                                                    <span class="wizard-preview-detail-label">Prod Unit:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['default_production_unit'] }}</span>
                                                </div>
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-gauge"></i>
                                                    <span class="wizard-preview-detail-label">Efficient:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['threshold_efficient'] }}</span>
                                                </div>
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-bolt"></i>
                                                    <span class="wizard-preview-detail-label">EB/CFT:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['eb_per_cft'] }}</span>
                                                </div>
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-calendar-day"></i>
                                                    <span class="wizard-preview-detail-label">Prod Hrs Mandatory:</span>
                                                    <span class="wizard-preview-detail-value">{{ ($state['production_hours_mandatory'] ?? false) ? 'Yes' : 'No' }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <h4 class="text-xs font-semibold mt-3 mb-1">Edit Windows</h4>
                                        <div class="wizard-preview-detail-grid">
                                            <div class="flex flex-col gap-1.5">
                                                @if($state['has_pith_blocks'])
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-calendar-days"></i>
                                                    <span class="wizard-preview-detail-label">Blocks:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['blocks_entry_days'] }} days</span>
                                                </div>
                                                @endif
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-calendar-days"></i>
                                                    <span class="wizard-preview-detail-label">Loads:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['loads_entry_days'] }} days</span>
                                                </div>
                                                @if($state['has_pith_bales'])
                                                <div class="wizard-preview-detail-item">
                                                    <i class="fa-solid fa-calendar-days"></i>
                                                    <span class="wizard-preview-detail-label">Bales:</span>
                                                    <span class="wizard-preview-detail-value">{{ $state['bales_entry_days'] }} days</span>
                                                </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @elseif(in_array($state['module'], ['fibre', 'growbags']))
                                <div class="wizard-preview-detail text-xs">
                                    <div class="wizard-preview-detail-left">
                                        <div class="wizard-preview-detail-icon-badge">
                                            <i class="fa-solid fa-sliders"></i>
                                        </div>
                                    </div>
                                    <div class="wizard-preview-detail-right">
                                        <h3 class="wizard-preview-detail-title">Customization</h3>
                                        <div class="wizard-preview-detail-grid">
                                            <div class="wizard-preview-detail-item">
                                                <i class="fa-solid fa-weight-scale"></i>
                                                <span class="wizard-preview-detail-label">Less Weight Alert:</span>
                                                <span class="wizard-preview-detail-value">{{ $state['less_weight_alert'] ?? '—' }} kg</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endif

                        {{-- Owner Details --}}
                        <div class="wizard-preview-detail text-xs">
                            <div class="wizard-preview-detail-left">
                                <div class="wizard-preview-detail-icon-badge">
                                    <i class="fa-solid fa-user"></i>
                                </div>
                            </div>
                            <div class="wizard-preview-detail-right">
                                <h3 class="wizard-preview-detail-title">Owner Details</h3>
                                <div class="wizard-preview-detail-grid">
                                    <div class="flex flex-col gap-1.5">
                                        <div class="wizard-preview-detail-item">
                                            <i class="fa-solid fa-signature"></i>
                                            <span class="wizard-preview-detail-label">Name:</span>
                                            <span class="wizard-preview-detail-value">{{ $state['owner_name'] ?: '—' }}</span>
                                        </div>
                                        <div class="wizard-preview-detail-item">
                                            <i class="fa-solid fa-envelope"></i>
                                            <span class="wizard-preview-detail-label">Email:</span>
                                            <span class="wizard-preview-detail-value">{{ $state['owner_email'] ?: '—' }}</span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <div class="wizard-preview-detail-item">
                                            <i class="fa-brands fa-whatsapp text-green-500" style="color: #25D366 !important; font-size:1.05rem;"></i>
                                            <span class="wizard-preview-detail-label">WhatsApp:</span>
                                            <span class="wizard-preview-detail-value">{{ $state['owner_whatsapp'] ?: '—' }}</span>
                                        </div>
                                        <div class="wizard-preview-detail-item" x-data="{ showPass: false }">
                                            <i class="fa-solid fa-lock"></i>
                                            <span class="wizard-preview-detail-label">Password:</span>
                                            <span class="wizard-preview-detail-value" x-text="showPass ? '{{ $state['owner_password'] }}' : '••••••••'"></span>
                                            <i class="fa-regular cursor-pointer ml-1 opacity-60 hover:opacity-100" :class="showPass ? 'fa-eye' : 'fa-eye-slash'" @click.stop="showPass = !showPass"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p class="text-center text-[var(--brown)] text-xs pt-1">Review all details above, then click <strong>Create factory</strong> to provision.</p>
                    </div>
                @endif
            </div>

            <!-- Footer Bar -->
            <div class="factory-wizard-footer">
                <button type="button" wire:click="previousStep" class="btn-wizard-secondary {{ $currentStep == 1 ? 'invisible' : '' }}">
                    Back
                </button>

                @if($currentStep < $totalSteps)
                    <button type="button" wire:click="nextStep" class="btn-wizard-primary" wire:loading.attr="disabled" wire:target="nextStep">
                        <span wire:loading.remove wire:target="nextStep">Continue</span>
                        <span wire:loading wire:target="nextStep"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Validating...</span>
                    </button>
                @else
                    <button type="button" wire:click="createTeam" class="btn-wizard-submit" wire:loading.attr="disabled" wire:target="createTeam">
                        <span wire:loading.remove wire:target="createTeam">Create factory</span>
                        <span wire:loading wire:target="createTeam" class="inline-flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Creating Factory...
                        </span>
                    </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Centered Loading Overlay with Smooth Fade Transition -->
    <div wire:loading wire:target="createTeam"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed inset-0 z-[9999] flex flex-col items-center justify-center bg-white/80 backdrop-blur-sm">
        <div class="bg-white p-6 rounded-xl shadow-2xl border border-[var(--line)] flex flex-col items-center gap-3 text-center max-w-xs mx-auto">
            <svg class="animate-spin h-10 w-10 text-[var(--rust)] mb-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <h4 class="font-semibold text-base text-[var(--ink)]">Creating Factory...</h4>
            <p class="text-xs text-[var(--brown)]">Please wait while we provision your environment.</p>
        </div>
    </div>
</div>
