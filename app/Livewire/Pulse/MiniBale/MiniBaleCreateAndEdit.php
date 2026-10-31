<?php

namespace App\Livewire\Pulse\MiniBale;

use App\Models\Pulse\Device;
use App\Models\Pulse\MiniBaleProduction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class MiniBaleCreateAndEdit extends Component
{
    public $productionId;

    public $teamId;

    public $date;

    public $startTime;

    public $endTime;

    public $staffId;

    public $balesProduced;

    public $balesExpected = 0;

    public $comments;

    public $showActuals = true;

    public $staffs;

    public $machines = [];

    public $machineId = null;

    public $machineName = null;

    // Stock fields (shown on edit)
    public $soldQuantity = 0;

    public $correctionType = 'add';

    public $correctionAmount;

    public $correctionComment;

    public $recordOpening = 0;

    public $recordProduced = 0;

    public function mount($id = null)
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module || ! $currentTeam->has_pith_module_bales) {
            return redirect("/{$currentTeam->id}/dashboard");
        }

        $this->teamId = $currentTeam->id;
        $this->showActuals = $currentTeam->showActuals();

        $this->machines = DB::table('pulse_master_minibale_machines')
            ->where('team_id', $this->teamId)
            ->where('is_active', 1)
            ->orderBy('machine_name')
            ->get()
            ->toArray();

        $policy = MiniBaleProduction::entryPolicyFor($this->teamId);

        if ($id) {
            $production = MiniBaleProduction::findOrFail($id);

            if (! $production->canEditRecord()) {
                session()->flash('error', 'This fibre production record is locked or outside the allowed edit window.');

                return redirect()->route('fibre.index', ['team' => $this->teamId]);
            }

            $this->productionId = $production->id;
            $this->date = $production->shift_date;
            $this->startTime = $production->start_time;
            $this->endTime = $production->end_time;
            $this->teamId = $production->team_id;
            $this->staffId = $production->staff_id;
            $this->balesProduced = $production->bales_produced;
            $this->balesExpected = $production->bales_expected ?? 0;
            $this->comments = $production->comments;
            $this->machineId = $production->minibale_machine_id;
            $this->machineName = $production->machine_name;
            $this->loadStockFromRecord($production);
        } else {
            $this->date = $policy->maxCreateShiftDate();

            if (count($this->machines) === 1) {
                $this->machineId = $this->machines[0]->id;
                $this->machineName = $this->machines[0]->machine_name;
            }
        }

        $this->staffs = $currentTeam->staffs()
            ->where(function ($query) {
                $query->where('is_active', true)
                    ->orWhere('id', $this->staffId);
            })
            ->orderBy('staff_name', 'asc')
            ->get();
    }

    public function updatedMachineId($value)
    {
        $machine = collect($this->machines)->firstWhere('id', (int) $value);
        $this->machineName = $machine?->machine_name ?? null;
    }

    public function updatedDate($value): void
    {
        if ($this->productionId) {
            $production = MiniBaleProduction::find($this->productionId);
            if ($production) {
                $this->loadStockFromRecord($production);
            }
        }
    }

    public function save()
    {
        $capability = $this->productionId ? 'fibre_bales.edit' : 'fibre_bales.create';
        if (! Gate::allows($capability)) {
            session()->flash('error', 'You are not authorized to save fibre production records');

            return;
        }

        $policy = MiniBaleProduction::entryPolicyFor((int) $this->teamId);

        $rules = [
            'date' => $this->productionId
                ? 'required|date|after_or_equal:'.$policy->minShiftDate().'|before_or_equal:'.$policy->liveShiftDay()
                : 'required|date|after_or_equal:'.$policy->minShiftDate().'|before_or_equal:'.$policy->maxCreateShiftDate(),
            'staffId' => 'nullable|exists:staffs,id',
            'machineId' => count($this->machines) > 1 ? 'required' : 'nullable',
            'balesProduced' => $this->showActuals ? 'required|integer' : 'nullable|integer',
            'comments' => 'nullable|string',
        ];

        if ($this->productionId) {
            $rules['soldQuantity'] = 'nullable|integer|min:0';
            $rules['correctionType'] = 'nullable|in:add,deduct';
            $rules['correctionAmount'] = 'nullable|integer|min:0';
            $rules['correctionComment'] = 'nullable|string|max:150';
        }

        $this->validate($rules, [
            'machineId.required' => 'Please select a machine.',
        ]);

        $this->date = Carbon::parse($this->date)->toDateString();

        if ($this->productionId) {
            $existing = MiniBaleProduction::where('team_id', $this->teamId)->find($this->productionId);
            if (! $existing || ! $policy->canEdit($this->date)) {
                $this->addError('date', 'Date is outside the allowed edit window.');

                return;
            }
        } elseif (! $policy->canCreate($this->date)) {
            $this->addError('date', 'Date is outside the allowed create window.');

            return;
        }

        $exists = MiniBaleProduction::where('shift_date', $this->date)
            ->where('team_id', $this->teamId)
            ->when($this->productionId, function ($query) {
                return $query->where('id', '!=', $this->productionId);
            })
            ->exists();

        if ($exists) {
            $this->addError('duplicate', 'Fibre production already exists for this date.');

            return;
        }

        $device = Device::where('team_id', $this->teamId)
            ->where('module', 'pith')
            ->first();

        if ($this->productionId) {
            $production = MiniBaleProduction::where('team_id', $this->teamId)->find($this->productionId);
            if (! $production || ! $production->canEditRecord()) {
                session()->flash('error', 'This fibre production record is locked or outside the allowed edit window.');

                return redirect()->route('fibre.index', ['team' => $this->teamId]);
            }

            $signedCorrection = ($this->correctionAmount ?? 0) > 0
                ? ($this->correctionType === 'deduct' ? -1 : 1) * (int) $this->correctionAmount
                : 0;

            $production->update([
                'shift_date' => $this->date,
                'staff_id' => $this->staffId,
                'bales_produced' => $this->balesProduced,
                'comments' => $this->comments,
                'minibale_machine_id' => $this->machineId,
                'machine_name' => $this->machineName,
                'sold' => (int) ($this->soldQuantity ?? 0),
                'correction' => $signedCorrection,
                'correction_comment' => $this->correctionComment,
                'updated_by' => Auth::id(),
            ]);
        } else {
            MiniBaleProduction::create([
                'team_id' => $this->teamId,
                'shift_date' => $this->date,
                'device_id' => $device->device_id ?? null,
                'staff_id' => $this->staffId,
                'start_time' => now()->startOfDay(),
                'end_time' => now()->endOfDay(),
                'bales_produced' => $this->balesProduced,
                'comments' => $this->comments,
                'minibale_machine_id' => $this->machineId,
                'machine_name' => $this->machineName,
                'created_by' => Auth::id(),
            ]);
        }

        session()->flash('message', 'Fibre production saved successfully.');

        return redirect()->route('fibre.index', ['team' => $this->teamId]);
    }

    public function render()
    {
        $policy = MiniBaleProduction::entryPolicyFor((int) $this->teamId);

        return view('pulse::livewire.minibale.minibale-create-and-edit', [
            'entryPolicy' => $policy,
            'minDate' => $policy->minShiftDate(),
            'maxDate' => $this->productionId ? $policy->liveShiftDay() : $policy->maxCreateShiftDate(),
        ]);
    }

    private function loadStockFromRecord(MiniBaleProduction $production): void
    {
        $this->soldQuantity = $production->sold ?? 0;
        $correction = (int) ($production->correction ?? 0);
        $this->correctionType = $correction < 0 ? 'deduct' : 'add';
        $this->correctionAmount = $correction !== 0 ? abs($correction) : null;
        $this->correctionComment = $production->correction_comment;

        $previous = MiniBaleProduction::where('team_id', $this->teamId)
            ->whereDate('shift_date', '<', $production->shift_date)
            ->orderByDesc('shift_date')
            ->first();

        $this->recordOpening = (int) ($previous?->bales_in_stock ?? 0);
        $this->recordProduced = $production->producedForStock();
    }
}
