<?php

namespace App\Livewire\Pulse\Pith;

use App\Exceptions\PithDistributionException;
use App\Http\Controllers\Pulse\Api\PithProductionController;
use App\Models\Pulse\Device;
use App\Models\Pulse\PithLocation;
use App\Models\Pulse\PithProduction;
use App\Models\Pulse\Shift;
use App\Models\Settings;
use App\Traits\ManagePithInventory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class PithCreateAndEdit extends Component
{
    use ManagePithInventory;

    public $productionId;

    public $shiftDate;

    public $shiftId;

    public $teamId;

    public $staffId;

    public $startTime;

    public $endTime;

    public $loadProduced;

    public $loadHours;

    public $comments;

    public $currentTeam;

    public $staffs;

    public $ebUnits = 0;

    public $ebAmount = 0;

    public $totalLoadsOffloaded;

    public $shifts;

    public $shiftSelected = false;

    public $existingProductions = [];

    public $editing = false;

    public $pithHrsValidationSetting;

    public $productionUnit = 'loads';

    public $cftPerLoad = 0.0;

    public $pithLocations;

    public $pithLocationLoads = [];

    public $inventoryPopUp = false;

    public $dateTimeFormat;

    protected $pithProdController;

    public $loadsExpected;

    public $showActuals = true;

    public function __construct()
    {
        $this->pithProdController = new PithProductionController;
    }

    public function mount($id = null)
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }

        $this->dateTimeFormat = config('app.date_time_format');

        $policy = PithProduction::entryPolicyFor($this->teamId ?? $currentTeam->id);

        // If editing, populate the form fields with existing data
        if ($id) {
            $production = PithProduction::findOrFail($id);

            if (! $production->canEditRecord()) {
                session()->flash('error', 'This pith load record is locked or outside the allowed edit window.');

                return redirect()->route('pith.index', ['team' => $production->team_id]);
            }

            $this->editing = true;

            $this->productionId = $production->id;
            $this->teamId = $production->team_id;
            $this->staffId = $production->staff_id;
            $this->shiftId = $production->shift_id;
            $this->shiftDate = $production->shift_date;
            $this->startTime = Carbon::parse($production->start_time)->roundMinute()->format('Y-m-d H:i');
            $this->endTime = Carbon::parse($production->end_time)->roundMinute()->format('Y-m-d H:i');
            $this->loadProduced = $production->cft_produced;
            $this->loadHours = $production->load_hrs;
            $this->ebUnits = $production->eb_units;
            $this->ebAmount = $production->eb_price;
            $this->comments = $production->comments;
            $this->shiftSelected = true;
            $this->loadsExpected = $production->cft_expected;
        } else {
            $this->shiftDate = $policy->maxCreateShiftDate();
        }
        session()->forget('pithLocationLoads');

        $this->currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        $this->teamId = $this->currentTeam->id;
        $this->showActuals = $this->currentTeam->showActuals();

        $setting = Settings::where('team_id', $this->teamId)
            ->where('module', 'pith')
            ->where('key', 'PRODUCTION_UNIT')
            ->first();
        if ($setting) {
            $this->productionUnit = strtolower((string) ($setting->value ?? 'loads')) === 'cft' ? 'cft' : 'loads';
            $this->cftPerLoad = (float) ($setting->value_remark ?? 0);
        }

        if ($this->productionId) {
            $inventoryData = $this->fetchInventoryData($this->productionId, $this->teamId);
            $this->pithLocations = $inventoryData['pithLocations'];
            $this->pithLocationLoads = $inventoryData['pithLocationLoads'];
            $this->totalLoadsOffloaded = $this->calculateTotalLoadsOffloaded();
            session()->put('pithLocationLoads', $this->pithLocationLoads);
        } else {
            $this->pithLocations = PithLocation::leftJoin('pulse_pith_location_groups', 'pulse_pith_locations.group_id', '=', 'pulse_pith_location_groups.id')
                ->where('pulse_pith_locations.team_id', $this->teamId)
                ->where('pulse_pith_locations.is_active', true)
                ->orderBy('pulse_pith_locations.name', 'asc')
                ->select(
                    'pulse_pith_locations.*',
                    'pulse_pith_location_groups.name as group_name',
                    'pulse_pith_location_groups.display_order as group_display_order'
                )
                ->get()
                ->toArray();

            // Initialize empty fields for user inputs
            foreach ($this->pithLocations as $location) {
                $this->pithLocationLoads[$location['id']] = [
                    'cft_offloaded' => '',
                    'is_archived' => 0,
                ];
            }
        }

        if ($this->productionId) {

            $this->staffs = $this->currentTeam->staffs()
                ->where(function ($query) {
                    $query->where('is_active', true)
                        ->orWhere('id', $this->staffId);
                })
                ->orderBy('staff_name', 'asc')
                ->get();

            $this->shifts = Shift::where('team_id', $this->teamId)
                ->where(function ($query) {
                    $query->where('is_active', true)
                        ->orWhere('id', $this->shiftId);
                })
                ->get();
        } else {
            $this->staffs = $this->currentTeam->staffs()
                ->where('is_active', true)
                ->orderBy('staff_name', 'asc')
                ->get();

            $this->loadShifts();

        }
        $this->loadexistingProductions();

        $this->pithHrsValidationSetting = Settings::where('key', 'PITHLOAD_HRS_MANDATORY')
            ->pluck('value')
            ->first();
    }

    public function updateShiftDate()
    {
        $this->loadShifts();
    }

    public function locationPopUp()
    {
        if (empty($this->loadProduced)) {
            $this->addError('loadProduced', 'Error: CFT produced must be entered to assign to inventory.');

            return;
        }

        $this->resetErrorBag('loadProduced');
        $this->inventoryPopUp = ! $this->inventoryPopUp;
    }

    public function updated($propertyName)
    {
        if (str_starts_with($propertyName, 'pithLocationLoads')) {
            $computedTotal = $this->calculateTotalLoadsOffloaded();
            if ($computedTotal > $this->getActualLoadsProduced()) {
                $this->addError('totalLoadsOffloaded', 'Total CFT offloaded cannot exceed CFT produced.');
            } else {
                $this->resetErrorBag('totalLoadsOffloaded');
            }
        } elseif ($propertyName === 'loadProduced') {
            $computedTotal = $this->calculateTotalLoadsOffloaded();
            if ($computedTotal > 0 && $computedTotal > $this->getActualLoadsProduced()) {
                $this->addError('loadProduced', 'Total CFT offloaded in inventory exceeds CFT produced.');
            } else {
                $this->resetErrorBag('loadProduced');
            }
        } else {
            $this->resetErrorBag($propertyName);
        }
    }

    public function calculateTotalLoadsOffloaded()
    {
        $loads = array_column($this->pithLocationLoads, 'cft_offloaded');

        foreach ($loads as &$load) {
            $load = is_numeric($load) ? (float) $load : 0;
        }

        return array_sum($loads);
    }

    public function updateLoads()
    {
        // Calculate the total loads offloaded
        $computedTotal = $this->calculateTotalLoadsOffloaded();

        // Check if total loads offloaded exceeds loads produced
        if ($computedTotal > $this->getActualLoadsProduced()) {
            $this->addError('totalLoadsOffloaded', 'Total CFT offloaded cannot exceed CFT produced.');

            return;
        }

        $this->totalLoadsOffloaded = $computedTotal;

        // Temporarily store the location offloaded data
        session()->put('pithLocationLoads', $this->pithLocationLoads);
        $this->inventoryPopUp = false;
    }

    public function loadShifts()
    {
        $this->shifts = Shift::where('team_id', $this->teamId)
            ->where('is_active', true)
            ->whereNotIn('id', function ($query) {
                $query->select('shift_id')
                    ->from('pulse_summary_pith_productions')
                    ->where('team_id', $this->teamId)
                    ->where('shift_date', $this->shiftDate);
            })
            ->orderBy('id', 'asc')
            ->get();
    }

    // Fetch existing shifts for the selected date from the database
    public function loadexistingProductions()
    {
        $this->existingProductions = PithProduction::where('team_id', $this->teamId)
            ->where('shift_date', $this->shiftDate)
            ->orderBy('start_time', 'asc')
            ->get();
    }

    public function updatedShiftDate($value)
    {
        $this->shiftDate = $value;
        $this->loadexistingProductions();

        $this->resetErrorBag('shiftId');
        $this->shiftSelected = false;
        $this->shiftId = '';

        $this->startTime = '';
        $this->endTime = '';

        // $this->dispatch('shiftDate-updated', title:$value); // Why is this line needed, Muthiah?
    }

    public function updatedShiftId($value)
    {
        $this->resetErrorBag();
        if ($this->checkShiftExists()) {
            $this->startTime = '';
            $this->endTime = '';

            return;
        }

        // Populate startTime and endTime based on $shifts data
        $this->shiftSelected = true;

        $shift = collect($this->shifts)->firstWhere('id', $value);

        if (! $shift) {
            $this->addError('shiftId', 'Please select shift for further procceding');
            $this->startTime = '';
            $this->endTime = '';

            return;
        }

        // Physical window derived from the team's shift-day anchor
        [$start, $end] = $shift->windowOn(Carbon::parse($this->shiftDate)->toDateString());
        $this->startTime = $start->toDateTimeString();
        $this->endTime = $end->toDateTimeString();

        $response = $this->pithProdController->getEb($this->startTime, $this->endTime)->getData();
        $this->ebUnits = $response->ebUnits ?? 0;
        $this->ebAmount = $response->ebAmount ?? 0;
    }

    public function updatedStartTime()
    {
        if ($this->endTime && Carbon::parse($this->startTime)->greaterThan(Carbon::parse($this->endTime))) {
            $this->addError('startTime', 'Start Time cannot be greater than End Time.');

            return;
        }

        $response = $this->pithProdController->getEb($this->startTime, $this->endTime)->getData();
        $this->ebUnits = $response->ebUnits ?? 0;
        $this->ebAmount = $response->ebAmount ?? 0;
    }

    public function updatedEndTime()
    {
        if ($this->startTime && Carbon::parse($this->startTime)->greaterThan(Carbon::parse($this->endTime))) {
            $this->addError('endTime', 'End Time cannot be less than Start Time.');

            return;
        }

        $response = $this->pithProdController->getEb($this->startTime, $this->endTime)->getData();
        $this->ebUnits = $response->ebUnits ?? 0;
        $this->ebAmount = $response->ebAmount ?? 0;
    }

    public function save()
    {
        $capability = $this->productionId ? 'pith_production.edit' : 'pith_production.create';
        if (! Gate::allows($capability)) {
            session()->flash('error', 'You are not authorized to save pith production records');

            return;
        }

        $policy = PithProduction::entryPolicyFor((int) $this->teamId);

        if ($this->productionId) {
            $existing = PithProduction::find($this->productionId);
            if (! $existing || ! $policy->canEdit($this->shiftDate)) {
                $this->addError('shiftDate', 'Shift date is outside the allowed edit window.');

                return;
            }
        } elseif (! $policy->canCreate($this->shiftDate)) {
            $this->addError('shiftDate', 'Shift date is outside the allowed create window.');

            return;
        }

        $this->pithLocationLoads = session()->get('pithLocationLoads', []);
        $startTime = Carbon::parse($this->startTime)->format('Y-m-d H:i:s');
        $endTime = Carbon::parse($this->endTime)->subSecond()->format('Y-m-d H:i:s');

        // Validate loads produced is not negative or zero (only when Actuals are captured)
        if ($this->showActuals && (empty($this->loadProduced) || $this->loadProduced <= 0)) {
            $this->addError('loadProduced', 'CFT produced must be greater than zero.');

            return;
        }

        // Validate load hours is not negative
        if (! empty($this->loadHours) && $this->loadHours < 0) {
            $this->addError('loadHours', 'Load hours cannot be negative.');

            return;
        }

        // Validate EB units and amount are not negative
        if (! empty($this->ebUnits) && $this->ebUnits < 0) {
            $this->addError('ebUnits', 'EB units cannot be negative.');

            return;
        }

        if (! empty($this->ebAmount) && $this->ebAmount < 0) {
            $this->addError('ebAmount', 'EB amount cannot be negative.');

            return;
        }

        if ($this->pithHrsValidationSetting === 'yes' && empty($this->loadHours)) {
            $this->addError('loadHours', 'The load hours field is required.');

            return;
        }

        $this->resetErrorBag();
        if ($this->checkShiftExists()) {
            $this->addError('shiftId', 'Error: This shift already exist.');

            return;
        }

        $device = Device::where('team_id', $this->currentTeam->id)
            ->where('module', 'pith')
            ->first();

        if ($this->pithProdController->checkOverlap($this->currentTeam->id, $device->device_id ?? null, $startTime, $endTime, $this->productionId)) {
            $this->addError('overlap', 'Error: Shift timings overlap with an existing production shift.');

            return;
        }

        $actualLoads = $this->getActualLoadsProduced();

        $production = null;

        if ($this->productionId) {
            $production = PithProduction::find($this->productionId);

            if (! $production || ! $production->canEditRecord()) {
                session()->flash('error', 'This pith load record is locked or outside the edit window.');

                return redirect()->route('pith.index', ['team' => $this->teamId]);
            }
        }

        // Reject an over-allocated distribution BEFORE anything is written. The same
        // comparison inside updateInventoryLoads() runs after cft_produced has already
        // been saved, so lowering produced below the offloaded total showed the operator
        // an error while leaving the production row lowered and the loads untouched —
        // exactly the mismatch system:check_errors reports every night afterwards.
        $distributionError = $this->inventoryDistributionError(
            $this->productionId,
            $this->pithLocationLoads,
            $actualLoads
        );

        if ($distributionError !== null) {
            $this->addError('inventoryError', $distributionError);
            session()->flash('error', $distributionError);

            return;
        }

        $shift = Shift::find($this->shiftId);
        $shiftName = $shift ? $shift->name : null;

        // The production row and its loads are one change. Writing them separately is
        // what allowed a rejected distribution to leave the lowered cft_produced behind.
        try {
            DB::transaction(function () use ($production, $actualLoads, $shiftName, $startTime, $endTime, $device) {
                if ($production) {
                    $production->update([
                        'team_id' => $this->currentTeam->id,
                        'staff_id' => $this->staffId,
                        'shift_id' => $this->shiftId,
                        'shift_date' => $this->shiftDate,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'cft_produced' => $actualLoads,
                        'load_hrs' => $this->loadHours,
                        'eb_units' => $this->ebUnits,
                        'eb_price' => $this->ebAmount,
                        'comments' => $this->comments,
                    ]);
                } else {
                    $created = PithProduction::create([
                        'team_id' => $this->currentTeam->id,
                        'staff_id' => $this->staffId,
                        'shift_id' => $this->shiftId,
                        'shift_name' => $shiftName,
                        'shift_date' => $this->shiftDate,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'device_id' => $device->device_id ?? null,
                        'cft_produced' => $actualLoads,
                        'load_hrs' => $this->loadHours,
                        'eb_units' => $this->ebUnits,
                        'eb_price' => $this->ebAmount,
                        'comments' => $this->comments,
                        'cft_expected' => $this->loadsExpected ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $this->productionId = $created->id; // Set the productionId after creating the record
                }

                $result = $this->updateInventoryLoads($this->productionId, $this->pithLocationLoads, $actualLoads, $this->shiftDate);

                // Pre-validated above, so this only fires if the loads moved underneath
                // us. Throwing rolls the production row back with them.
                if (isset($result['error'])) {
                    throw new PithDistributionException($result['error']);
                }
            });
        } catch (PithDistributionException $e) {
            $this->addError('inventoryError', $e->getMessage());
            session()->flash('error', $e->getMessage());

            return;
        }

        // Flash a success message to the session
        session()->flash('message', 'Pith production saved successfully.');

        // Redirect to the list page after saving
        return redirect()->route('pith.index', ['team' => $this->teamId]);
    }

    private function getActualLoadsProduced(): float
    {
        return (float) $this->loadProduced;
    }

    protected function checkShiftExists()
    {
        foreach ($this->existingProductions as $production) {
            $prodShiftId = is_array($production) ? ($production['shift_id'] ?? null) : ($production->shift_id ?? null);
            $prodId = is_array($production) ? ($production['id'] ?? null) : ($production->id ?? null);

            if ($prodShiftId == $this->shiftId && $prodId != $this->productionId) {
                return true;
            }
        }

        return false;
    }

    public function render()
    {
        $policy = PithProduction::entryPolicyFor((int) $this->teamId);

        return view('pulse::livewire.pith.pith-create-edit', [
            'entryPolicy' => $policy,
            'minDate' => $policy->minShiftDate(),
            'maxDate' => $this->productionId ? $policy->liveShiftDay() : $policy->maxCreateShiftDate(),
        ]);
    }
}
