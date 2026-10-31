<?php

namespace App\Livewire;

use App\Models\Pulse\Device;
use App\Models\Pulse\GrowbagMachine;
use App\Models\Pulse\MasterMiniBaleMachine;
use App\Models\Pulse\MasterWaterMeter;
use App\Models\Pulse\MasterWaterTank;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\Shift;
use App\Models\Team;
use App\Models\User;
use App\Rules\CoordinatePair;
use App\Support\Pulse\GeofencingModule;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use App\Support\Pulse\TeamRolePermissions;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class CreateTeamForm extends Component
{
    public int $currentStep = 1;

    public array $state = [
        'name' => '',

        // Step 1: Products
        'pulse_enabled' => true,
        'device_id' => '',
        'eb_units' => 8.5,
        'pulse_license_expiry' => '',

        // Step 2: Config
        'module' => '',
        'has_pith_bales' => false,
        'pith_bales_count' => 1,
        'has_pith_blocks' => true,
        'pith_bales_machines' => [['field_id' => '', 'name' => '']],
        'has_fibre_weight' => false,
        'growbags_count' => 1,
        'growbags_machines' => [['field_id' => '', 'name' => '', 'bags' => '']],
        'attendance_enabled' => true,
        'geofencing_enabled' => false,
        'geofencing_max_boundaries' => 5,
        'has_water_meter' => false,
        'water_meters_count' => 1,
        'water_meters' => [['type' => 'd27', 'name' => '']],
        'has_water_tank' => false,
        'water_tanks_count' => 1,
        'water_tanks' => [['type' => 'd29', 'name' => '', 'capacity' => '', 'alert_low' => '5', 'alert_high' => '98']],
        'address_line_1' => '',
        'address_line_2' => '',
        'city' => '',
        'state' => '',
        'pincode' => '',
        'coordinates' => '',
        'shift_count' => 2,
        'shift_start' => '08:00',
        'shifts' => [],

        // Step 3: Settings
        'blocks_entry_days' => 10,
        'loads_entry_days' => 10,
        'bales_entry_days' => 10,
        'threshold_off' => 5,
        'threshold_underloaded' => 30,
        'threshold_efficient' => 70,
        'message_interval' => 60,
        'missing_check_duration' => 300,
        'less_weight_alert' => 120,
        'eb_per_cft' => 0.318,
        'husks_per_cft' => 1.89,
        'production_hours_mandatory' => false,
        'default_production_unit' => 'cft',

        // Step 4: Owner
        'owner_name' => '',
        'owner_whatsapp' => '',
        'owner_email' => '',
        'owner_password' => '',
    ];

    public bool $isPasswordDirty = false;

    public function mount()
    {
        // Device ID starts empty by default
        $this->state['device_id'] = '';

        // Default license expiry
        $this->state['pulse_license_expiry'] = now()->addYear()->toDateString();

        $this->state['geofencing_max_boundaries'] = GeofencingModule::defaultMaxBoundaries();

        $this->generateShifts();
    }

    public function updated($propertyName)
    {
        if (str_starts_with($propertyName, 'state.')) {
            $rules = $this->getRulesForCurrentStep();
            if (isset($rules[$propertyName])) {
                try {
                    $this->validateOnly($propertyName, $rules, $this->getMessagesForCurrentStep());
                } catch (ValidationException $e) {
                    // Handled automatically by Livewire error bag
                }
            }
        }

        if (in_array($propertyName, ['state.name', 'state.device_id']) && ! $this->isPasswordDirty) {
            $name = preg_replace('/[^a-zA-Z0-9]/', '', $this->state['name']);
            $did = $this->state['device_id'];
            if ($name || $did) {
                $this->state['owner_password'] = "{$name}@{$did}";
            }
        }

        if ($propertyName === 'state.owner_password') {
            $this->isPasswordDirty = true;
        }

        if (in_array($propertyName, ['state.shift_count', 'state.shift_start'])) {
            $this->generateShifts();
        }

        if ($propertyName === 'state.pith_bales_count') {
            $this->syncArrayCount('pith_bales_machines', $this->state['pith_bales_count'], ['field_id' => '', 'name' => '']);
        }
        if ($propertyName === 'state.growbags_count') {
            $this->syncArrayCount('growbags_machines', $this->state['growbags_count'], ['field_id' => '', 'name' => '', 'bags' => '']);
        }
        if ($propertyName === 'state.water_meters_count') {
            $this->syncArrayCount('water_meters', $this->state['water_meters_count'], ['type' => 'd27', 'name' => '']);
        }
        if ($propertyName === 'state.water_tanks_count') {
            $this->syncArrayCount('water_tanks', $this->state['water_tanks_count'], ['type' => 'd29', 'name' => '', 'capacity' => 175000, 'alert_low' => '5', 'alert_high' => '98']);
        }

        if (preg_match('/^state\.water_tanks\.(\d+)\.type$/', $propertyName, $matches)) {
            $index = (int) $matches[1];
            $type = $this->state['water_tanks'][$index]['type'] ?? '';
            $currentCapacity = $this->state['water_tanks'][$index]['capacity'] ?? '';

            if ($currentCapacity === '' || $currentCapacity === null || (int) $currentCapacity === 175000 || (int) $currentCapacity === 6000) {
                if ($type === 'd29') {
                    $this->state['water_tanks'][$index]['capacity'] = 175000;
                } elseif ($type === 'd30') {
                    $this->state['water_tanks'][$index]['capacity'] = 6000;
                }
            }
        }
    }

    private function syncArrayCount($arrayKey, $count, $defaultItem)
    {
        $count = max(1, min(10, (int) $count)); // cap 1-10
        $current = $this->state[$arrayKey] ?? [];
        if (count($current) > $count) {
            $this->state[$arrayKey] = array_slice($current, 0, $count);
        } else {
            while (count($this->state[$arrayKey]) < $count) {
                $this->state[$arrayKey][] = $defaultItem;
            }
        }
    }

    public function generateShifts()
    {
        $count = (int) $this->state['shift_count'];
        $this->state['shifts'] = [];

        if ($count === 2) {
            $this->state['shifts'] = [
                ['name' => 'Shift 1', 'start' => '08:00', 'end' => '18:00'],
                ['name' => 'Shift 2', 'start' => '18:00', 'end' => '08:00'],
            ];
        } else {
            $start = $this->state['shift_start'] ?: '08:00';
            $hours = 24 / $count;
            $time = Carbon::createFromFormat('H:i', $start);

            for ($i = 1; $i <= $count; $i++) {
                $startTime = $time->format('H:i');
                $time->addHours($hours);
                $endTime = $time->format('H:i');

                $this->state['shifts'][] = [
                    'name' => "Shift $i",
                    'start' => $startTime,
                    'end' => $endTime,
                ];
            }
        }
    }

    public function setStep($step)
    {
        if ($step > $this->currentStep) {
            $this->validateCurrentStep();
        }
        $this->currentStep = $step;
    }

    public function getStepsProperty()
    {
        $steps = [
            1 => ['title' => 'Factory & Product', 'subtitle' => 'Basic details'],
        ];

        $idx = 2;
        if ($this->state['pulse_enabled']) {
            $steps[$idx++] = ['title' => 'Pulse Configuration', 'subtitle' => 'Modules & limits'];
            $steps[$idx++] = ['title' => 'Pulse Settings', 'subtitle' => 'Advanced rules'];
        }

        $steps[$idx++] = ['title' => 'Owner Registration', 'subtitle' => 'Login details'];
        $steps[$idx++] = ['title' => 'Preview & Create', 'subtitle' => 'Final review'];

        return collect($steps);
    }

    public function getMessagesForCurrentStep(): array
    {
        return [
            'state.name.required' => 'Factory name is required.',
            'state.name.unique' => 'Factory name has already been taken.',
            'state.device_id.required' => 'Device ID is required.',
            'state.device_id.unique' => 'Device ID has already been registered.',
            'state.device_id.integer' => 'Device ID must be a number.',
            'state.eb_units.required' => 'Price per EB unit is required.',
            'state.pulse_license_expiry.required' => 'Pulse license expiry date is required.',
            'state.module.required' => 'Please select a Pulse module.',
            'state.coordinates.required' => 'Location coordinates are required.',
            'state.owner_name.required' => 'Owner name is required.',
            'state.owner_whatsapp.required' => 'WhatsApp number is required.',
            'state.owner_whatsapp.unique' => 'WhatsApp number is already registered.',
            'state.owner_email.required' => 'Email address is required.',
            'state.owner_email.email' => 'Please enter a valid email address.',
            'state.owner_email.unique' => 'Email address is already registered.',
            'state.owner_password.required' => 'Password is required.',
            'state.water_meters.*.type.distinct' => 'Each water meter must have a unique Field ID.',
            'state.water_tanks.*.type.distinct' => 'Each water tank must have a unique Field ID.',
        ];
    }

    public function getRulesForCurrentStep(): array
    {
        $stepInfo = $this->steps[$this->currentStep] ?? null;
        if (! $stepInfo) {
            return [];
        }

        $title = $stepInfo['title'];
        $rules = [];

        if ($title === 'Factory & Product') {
            $rules['state.name'] = ['required', 'string', 'max:255', 'unique:teams,name'];
            if ($this->state['pulse_enabled']) {
                $rules['state.pulse_license_expiry'] = ['required'];
                $rules['state.device_id'] = ['required', 'integer', 'unique:pulse_devices,device_id'];
                $rules['state.eb_units'] = ['required', 'numeric', 'min:0'];
            }
        } elseif ($title === 'Pulse Configuration') {
            $rules['state.module'] = ['required', 'string'];
            $rules['state.coordinates'] = ['required', 'string', 'max:60', new CoordinatePair];

            if ($this->state['has_water_meter']) {
                $rules['state.water_meters.*.type'] = ['required', 'distinct'];
                $rules['state.water_meters.*.name'] = ['required', 'string'];
            }
            if ($this->state['has_water_tank']) {
                $rules['state.water_tanks.*.type'] = ['required', 'distinct'];
                $rules['state.water_tanks.*.name'] = ['required', 'string'];
            }
            if ($this->state['geofencing_enabled']) {
                $rules['state.geofencing_max_boundaries'] = [
                    'required',
                    'integer',
                    'min:1',
                    'max:'.GeofencingModule::absoluteMaxBoundaries(),
                ];
            }
        } elseif ($title === 'Pulse Settings') {
            if ($this->state['module'] === 'pith') {
                $rules['state.threshold_off'] = ['required', 'numeric'];
                $rules['state.threshold_underloaded'] = ['required', 'numeric'];
                $rules['state.threshold_efficient'] = ['required', 'numeric'];
            }
        } elseif ($title === 'Owner Registration') {
            $rules['state.owner_name'] = ['required', 'string', 'max:255'];
            $rules['state.owner_whatsapp'] = ['required', 'string', 'unique:users,mobile'];
            $rules['state.owner_email'] = ['required', 'email', 'unique:users,email'];
            $rules['state.owner_password'] = ['required', 'string'];
        }

        return $rules;
    }

    public function validateCurrentStep()
    {
        $rules = $this->getRulesForCurrentStep();
        if (! empty($rules)) {
            $this->validate($rules, $this->getMessagesForCurrentStep());
        }

        return true;
    }

    public function nextStep()
    {
        // Step 1 guard: Pulse must be selected before proceeding
        $stepInfo = $this->steps[$this->currentStep] ?? null;
        if ($stepInfo && $stepInfo['title'] === 'Factory & Product') {
            if (! $this->state['pulse_enabled']) {
                $this->addError('products', 'Please enable Pulse to continue.');

                return;
            }
        }

        $this->validateCurrentStep();
        $steps = $this->steps;
        if ($this->currentStep < $steps->keys()->last()) {
            $this->currentStep++;
        }
    }

    public function previousStep()
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function createTeam()
    {
        $rules = [];
        if ($this->state['pulse_enabled']) {
            // pulse_devices.device_id is an unsigned int: anything else reaches the insert as
            // a truncation error and is shown to the superadmin as raw SQL.
            $rules['state.device_id'] = ['required', 'integer', 'unique:pulse_devices,device_id'];
        }
        $rules['state.owner_email'] = ['required', 'email', 'unique:users,email'];
        $rules['state.owner_whatsapp'] = ['required', 'unique:users,mobile'];
        $rules['state.name'] = ['required', 'unique:teams,name'];

        $this->validate($rules);

        DB::beginTransaction();
        try {
            // 1. Create User
            $user = User::create([
                'name' => $this->state['owner_name'],
                'email' => $this->state['owner_email'],
                'mobile' => $this->state['owner_whatsapp'],
                'password' => bcrypt($this->state['owner_password']),
                'is_superadmin' => 0,
                'is_active' => 1,
            ]);

            // 2. Module flags
            $hasPith = $this->state['pulse_enabled'] && $this->state['module'] === 'pith';
            $hasFibre = $this->state['pulse_enabled'] && $this->state['module'] === 'fibre';
            $hasGrowbags = $this->state['pulse_enabled'] && $this->state['module'] === 'growbags';

            // 3. Create Team — teams.products = simple flags only
            $teamProducts = [];
            if ($this->state['pulse_enabled']) {
                $teamProducts['pulse'] = true;
            }

            $team = Team::create([
                'name' => $this->state['name'],
                'products' => $teamProducts,
            ]);

            // 4. Assign Owner in team_user (with product_access)
            $productAccess = [];
            if ($this->state['pulse_enabled']) {
                $productAccess[] = 'pulse';
            }

            $user->teams()->attach($team->id, [
                'role' => 'owner',
                'product_access' => json_encode($productAccess),
            ]);

            // 5. Factory Address → team_address
            $factoryAddress = $team->address()->create([
                'type' => 'factory',
                'address_line_1' => $this->state['address_line_1'],
                'address_line_2' => $this->state['address_line_2'],
                'city' => $this->state['city'],
                'state' => $this->state['state'],
                'pincode' => $this->state['pincode'],
            ]);

            // 6. Pulse setup
            if ($this->state['pulse_enabled']) {
                // Full modules JSON for team_pulse.modules (matches legacy teams.products shape)
                $pulseModuleConfig = [
                    'pith' => ['is_active' => $hasPith,     'submodules' => ['blocks' => $hasPith ? (bool) $this->state['has_pith_blocks'] : false, 'bales' => $hasPith ? (bool) $this->state['has_pith_bales'] : false]],
                    'fibre' => ['is_active' => $hasFibre,    'submodules' => ['weight' => $hasFibre ? (bool) $this->state['has_fibre_weight'] : false]],
                    'growbags' => ['is_active' => $hasGrowbags],
                    'attendance' => ['is_active' => (bool) $this->state['attendance_enabled']],
                    'husks_stock' => ['is_active' => false],
                    'water_meter' => ['is_active' => (bool) $this->state['has_water_meter']],
                    'water_capacity' => ['is_active' => (bool) $this->state['has_water_tank']],
                    'geofencing' => GeofencingModule::payload(
                        (bool) $this->state['geofencing_enabled'],
                        (int) $this->state['geofencing_max_boundaries']
                    ),
                ];

                $pulseModules = [
                    'pulse' => [
                        'is_active' => true,
                        'modules' => $pulseModuleConfig,
                    ],
                ];

                // 6a. team_pulse
                $team->pulse()->create([
                    'factory_address_id' => $factoryAddress->id,
                    'coordinates' => $this->state['coordinates'],
                    'modules' => $pulseModules,
                    'is_active' => true,
                    'license_valid_till' => $this->state['pulse_license_expiry'] ?: now()->addYear()->toDateString(),
                    'installed_date' => now()->toDateString(),
                ]);

                TeamRolePermissions::seedDefaultsForTeam($team->id);

                if ($hasPith) {
                    $locations = [];
                    for ($i = 1; $i <= 8; $i++) {
                        $locations[] = [
                            'name' => 'Location '.$i,
                            'team_id' => $team->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    PithLocation::insert($locations);
                }

                $deviceId = $this->state['device_id'];

                // 6b. pulse_devices — Observer no longer pre-creates a device.
                // Create directly with all user-specified values.
                Device::create([
                    'device_id' => $deviceId,
                    'team_id' => $team->id,
                    'module' => $this->state['module'],
                    'status' => 'active',
                    'off_th' => $this->state['threshold_off'],
                    'eb_units' => $this->state['eb_units'] ?? null,
                    'idle_th' => $this->state['threshold_underloaded'],
                    'efficient_th' => $this->state['threshold_efficient'],
                    'duration_device_msg' => $this->state['message_interval'],
                    'duration_device_missing' => $this->state['missing_check_duration'],
                ]);

                // 6c. pulse_shifts — Observer no longer pre-creates shifts.
                // Create all shifts directly with user-specified times.
                foreach ($this->state['shifts'] as $shift) {
                    Shift::create([
                        'team_id' => $team->id,
                        'device_id' => $deviceId,
                        'name' => $shift['name'],
                        'start_time' => $shift['start'].':00',
                        'end_time' => $shift['end'].':00',
                        'is_active' => true,
                        'created_by' => $user->id,
                    ]);
                }

                // 6d. pulse_settings — Observer no longer seeds these. Create all rows here
                // with the actual user-provided values (correct anchor, correct EB units etc.)
                $settings = [];

                // Shift-day anchor: use Shift 1's start time so schedule is always in sync
                $shiftAnchor = ($this->state['shifts'][0]['start'] ?? '07:00').':00';
                $settings[] = [
                    'team_id' => $team->id,
                    'module' => 'shifts',
                    'key' => Shift::ANCHOR_SETTING_KEY,
                    'text' => 'Shift day anchor (start of the 24-hour production day)',
                    'value' => $shiftAnchor,
                    'options' => null,
                    'value_remark' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($hasPith) {
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'pith',
                        'key' => 'PITHLOAD_HRS_MANDATORY', 'text' => 'Is Pith Load Hrs a mandatory field?',
                        'value' => ($this->state['production_hours_mandatory'] ?? false) ? 'yes' : 'no',
                        'options' => 'no, yes', 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'pith',
                        'key' => 'EB_UNITS_PER_CFT', 'text' => 'EB Units per CFT',
                        'value' => (string) ($this->state['eb_per_cft'] ?? '0.318'),
                        'options' => '', 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'pith',
                        'key' => 'RS_PER_EB_UNIT', 'text' => 'Rupees per EB unit',
                        'value' => (string) ($this->state['eb_units'] ?? '9.50'),
                        'options' => '', 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'pith',
                        'key' => 'PRODUCTION_UNIT', 'text' => 'Default Production Unit',
                        'value' => $this->state['default_production_unit'] ?? 'cft',
                        'options' => 'loads,cft', 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'pith',
                        'key' => 'HUSKS_PER_CFT', 'text' => 'Approx. CFT of Husks per CFT of Pith',
                        'value' => (string) ($this->state['husks_per_cft'] ?? '1.89'),
                        'options' => null, 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'pith',
                        'key' => 'SHOW_ACTUALS', 'text' => 'Show Actuals alongside Estimated?',
                        'value' => 'yes', 'options' => 'no, yes', 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];

                    $policyNow = now();
                    $policyBase = [
                        'team_id' => $team->id,
                        'module' => 'pith',
                        'options' => ProductionEntryPolicyKeys::dayOptions(),
                        'value_remark' => null,
                        'created_at' => $policyNow,
                        'updated_at' => $policyNow,
                    ];
                    $settings[] = array_merge($policyBase, [
                        'key' => ProductionEntryPolicyKeys::BLOCKS,
                        'text' => ProductionEntryPolicyKeys::DEFINITIONS[ProductionEntryPolicyKeys::BLOCKS]['text'],
                        'value' => (string) ProductionEntryPolicyKeys::normalizeDay($this->state['blocks_entry_days'] ?? 10),
                    ]);
                    $settings[] = array_merge($policyBase, [
                        'key' => ProductionEntryPolicyKeys::LOADS,
                        'text' => ProductionEntryPolicyKeys::DEFINITIONS[ProductionEntryPolicyKeys::LOADS]['text'],
                        'value' => (string) ProductionEntryPolicyKeys::normalizeDay($this->state['loads_entry_days'] ?? 10),
                    ]);
                    if ($this->state['has_pith_bales'] ?? false) {
                        $settings[] = array_merge($policyBase, [
                            'key' => ProductionEntryPolicyKeys::BALES,
                            'text' => ProductionEntryPolicyKeys::DEFINITIONS[ProductionEntryPolicyKeys::BALES]['text'],
                            'value' => (string) ProductionEntryPolicyKeys::normalizeDay($this->state['bales_entry_days'] ?? 10),
                        ]);
                    }
                }

                if ($hasFibre) {
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'fibre',
                        'key' => 'ALERT_THRESHOLD_LESS_WEIGHT', 'text' => 'Alert Threshold for less weight (in kgs)',
                        'value' => (string) ($this->state['less_weight_alert'] ?? '120'),
                        'options' => '', 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'fibre',
                        'key' => 'RS_PER_EB_UNIT', 'text' => 'Rupees per EB unit',
                        'value' => (string) ($this->state['eb_units'] ?? '10.15'),
                        'options' => '', 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }

                /*
                 * Growbags reads the same rate as the other modules — ProcessDeviceDataForGrowBagsJob
                 * multiplies it by the EB units the device counted — and the wizard already asks for
                 * it, so the row was simply never written. Without it the job falls back to 0 and
                 * every growbag factory reports its whole EB cost as zero.
                 */
                if ($hasGrowbags) {
                    $settings[] = [
                        'team_id' => $team->id, 'module' => 'growbags',
                        'key' => 'RS_PER_EB_UNIT', 'text' => 'Rupees per EB unit',
                        'value' => (string) ($this->state['eb_units'] ?? '9.50'),
                        'options' => '', 'value_remark' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }

                if (! empty($settings)) {
                    \DB::table('pulse_settings')->insert($settings);
                }

                // 6e. pulse_master_minibale_machines (Fibre Bales) — not created by observer
                if ($hasPith && $this->state['has_pith_bales']) {
                    foreach ($this->state['pith_bales_machines'] as $machine) {
                        if (empty($machine['field_id']) && empty($machine['name'])) {
                            continue;
                        }
                        MasterMiniBaleMachine::create([
                            'team_id' => $team->id,
                            'device_id' => $deviceId,
                            'field_id' => $machine['field_id'],
                            'machine_name' => $machine['name'],
                            'is_active' => true,
                            'created_by' => $user->id,
                            'updated_by' => $user->id,
                        ]);
                    }
                }

                // 6f. pulse_master_growbag_machines — not created by observer
                if ($hasGrowbags) {
                    foreach ($this->state['growbags_machines'] as $machine) {
                        if (empty($machine['field_id']) && empty($machine['name'])) {
                            continue;
                        }
                        GrowbagMachine::create([
                            'team_id' => $team->id,
                            'device_id' => $deviceId,
                            'field_id' => $machine['field_id'],
                            'machine_name' => $machine['name'],
                            'bags_per_punch' => (int) ($machine['bags'] ?? 0),
                            'is_active' => true,
                            'created_by' => $user->id,
                            'updated_by' => $user->id,
                        ]);
                    }
                }

                // 6g. pulse_master_water_meters — not created by observer
                if ($this->state['has_water_meter']) {
                    foreach ($this->state['water_meters'] as $meter) {
                        if (empty($meter['name'])) {
                            continue;
                        }
                        MasterWaterMeter::create([
                            'device_id' => $deviceId,
                            'field_id' => $meter['type'], // d27 or d16
                            'consumption_name' => $meter['name'],
                        ]);
                    }
                }

                // 6h. pulse_master_water_tanks — not created by observer
                if ($this->state['has_water_tank']) {
                    foreach ($this->state['water_tanks'] as $tank) {
                        if (empty($tank['name'])) {
                            continue;
                        }
                        MasterWaterTank::create([
                            'device_id' => $deviceId,
                            'field_id' => $tank['type'], // d29 or d30
                            'tank_name' => $tank['name'],
                            'capacity_litres' => (float) ($tank['capacity'] ?? 175000),
                            'low_alert_percent' => (float) ($tank['alert_low'] ?? 5),
                            'overflow_alert_percent' => (float) ($tank['alert_high'] ?? 98),
                            'tank_type' => $tank['type'] === 'd29' ? 'underground' : 'overhead',
                        ]);
                    }
                }
            }

            // Switch team for new owner & currently logged in user (superadmin)
            $freshTeam = $team->fresh();
            $user->switchTeam($freshTeam);
            if (Auth::check()) {
                Auth::user()->switchTeam($freshTeam);
            }

            DB::commit();

            if ($hasPith ?? false) {
                return redirect()->route('dashboard.pith', ['team' => $team->id]);
            } elseif ($hasFibre ?? false) {
                return redirect()->route('dashboard.fibre', ['team' => $team->id]);
            } elseif ($hasGrowbags ?? false) {
                return redirect()->route('dashboard.growbags', ['team' => $team->id]);
            }

            return redirect("/{$team->id}/dashboard");

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Factory creation failed: '.$e->getMessage(), ['exception' => $e]);
            $this->addError('general', 'Failed to create factory: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.create-team-form');
    }
}
