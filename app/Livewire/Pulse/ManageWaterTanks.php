<?php

namespace App\Livewire\Pulse;

use App\Http\Controllers\Pulse\MasterWaterTankController;
use App\Models\Pulse\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManageWaterTanks extends Component
{
    public $tanks = [];

    public $tankDbId;

    public $device_id;

    public $field_id;

    public $tank_name;

    public $capacity_litres;

    public $low_alert_percent = 5;

    public $overflow_alert_percent = 98;

    public $showModal = false;

    public $editMode = false;

    public $teamId;

    public $tankTypes = [
        'd29' => 'Underground',
        'd30' => 'Overhead',
    ];

    protected $controller;

    public function __construct()
    {
        $this->controller = new MasterWaterTankController;
    }

    public function mount()
    {
        // Auto-fill device_id for current team
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $device = Device::where('team_id', $this->teamId)->first();
        $this->device_id = $device?->device_id; // nullable-safe

        $this->index();
    }

    // Load all tanks
    public function index()
    {
        $response = $this->controller->index($this->teamId);
        $this->tanks = $response->getData();
    }

    // Open modal for adding tank
    public function addTankModal()
    {
        if (! Auth::user()->isSuperadmin()) {
            session()->flash('error', 'Only Superadmin can add water tanks.');

            return;
        }

        $this->resetForm();
        $this->resetErrorBag();
        $this->field_id = 'd29';
        $this->capacity_litres = 175000;
        $this->tank_name = 'Underground Tank';
        $this->showModal = true;
    }

    public function updatedFieldId($value)
    {
        if (! $this->editMode) {
            if ($this->capacity_litres === '' || $this->capacity_litres === null || (int) $this->capacity_litres === 175000 || (int) $this->capacity_litres === 6000) {
                if ($value === 'd29') {
                    $this->capacity_litres = 175000;
                    $this->tank_name = 'Underground Tank';
                } elseif ($value === 'd30') {
                    $this->capacity_litres = 6000;
                    $this->tank_name = 'Overhead Tank';
                }
            }
        }
    }

    // Store a new tank
    public function store()
    {
        if (! Auth::user()->isSuperadmin()) {
            session()->flash('error', 'Only Superadmin can add water tanks');

            return;
        }

        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage water tanks');

            return;
        }

        // Always resolve device_id from team; never accept UI input.
        $device = Device::where('team_id', $this->teamId)->first();
        $this->device_id = $device?->device_id;

        $data = [
            'device_id' => $this->device_id,
            'field_id' => $this->field_id,
            'tank_name' => $this->tank_name,
            'capacity_litres' => $this->capacity_litres,
            'low_alert_percent' => $this->low_alert_percent,
            'overflow_alert_percent' => $this->overflow_alert_percent,
        ];

        $response = $this->controller->store(request()->merge($data), $this->teamId);

        if ($response->status() === 201) {
            $this->resetErrorBag();
            session()->flash('success', 'Water tank added successfully');
            $this->index();
            $this->resetForm();
            $this->showModal = false;
        } else {
            $errors = $response->getData()->error ?? ['Unexpected error'];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
        }
    }

    // Edit tank modal
    public function edit($id)
    {
        $response = $this->controller->edit($id, $this->teamId);
        $this->resetErrorBag();

        if ($response->status() === 200) {
            $tank = $response->getData();
            $this->tankDbId = $tank->id;
            $this->device_id = $tank->device_id;
            $this->field_id = $tank->field_id;
            $this->tank_name = $tank->tank_name;
            $this->capacity_litres = $tank->capacity_litres;
            $this->low_alert_percent = $tank->low_alert_percent ?? 5;
            $this->overflow_alert_percent = $tank->overflow_alert_percent ?? 98;
            $this->editMode = true;
            $this->showModal = true;
        } else {
            session()->flash('error', 'Failed to load tank for editing');
        }
    }

    // Update existing tank
    public function update()
    {
        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage water tanks');

            return;
        }

        // Keep existing device_id; never accept UI input.
        $existingResponse = $this->controller->edit($this->tankDbId, $this->teamId);
        $existingDeviceId = $this->device_id;
        if ($existingResponse->status() === 200) {
            $existingDeviceId = $existingResponse->getData()->device_id;
        }

        $isSuperadmin = Auth::user()->isSuperadmin();
        $isOwnerOnly = ! $isSuperadmin && Auth::user()->getCurrentTeamRole() === 'owner';

        $existingTank = $existingResponse->status() === 200 ? $existingResponse->getData() : null;

        $data = $isOwnerOnly
            ? [
                'device_id' => $existingDeviceId,
                'field_id' => $existingTank->field_id ?? $this->field_id,
                'tank_name' => $existingTank->tank_name ?? $this->tank_name,
                'capacity_litres' => $this->capacity_litres,
                'low_alert_percent' => $this->low_alert_percent,
                'overflow_alert_percent' => $this->overflow_alert_percent,
            ]
            : [
                'device_id' => $existingDeviceId,
                'field_id' => $this->field_id,
                'tank_name' => $this->tank_name,
                'capacity_litres' => $this->capacity_litres,
                'low_alert_percent' => $this->low_alert_percent,
                'overflow_alert_percent' => $this->overflow_alert_percent,
            ];

        $response = $this->controller->update(request()->merge($data), $this->tankDbId, $this->teamId);

        if ($response->status() === 200) {
            $this->resetErrorBag();
            session()->flash('success', 'Water tank updated successfully');
            $this->index();
            $this->resetForm();
            $this->showModal = false;
        } else {
            $errors = $response->getData()->error ?? [];
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
        }
    }

    // Delete tank
    public function delete($id)
    {
        if (! Auth::user()->isSuperadmin()) {
            session()->flash('error', 'Only Superadmin can delete water tanks');

            return;
        }

        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage water tanks');

            return;
        }

        $response = $this->controller->destroy($id, $this->teamId);

        if ($response->status() === 200) {
            session()->flash('success', 'Water tank deleted successfully');
            $this->index();
        } else {
            session()->flash('error', 'Failed to delete water tank');
        }
    }

    // Reset form fields
    private function resetForm()
    {
        $this->reset([
            'tankDbId',
            'field_id',
            'tank_name',
            'capacity_litres',
            'showModal',
            'editMode',
        ]);

        $this->low_alert_percent = 5;
        $this->overflow_alert_percent = 98;

        $device = Device::where('team_id', $this->teamId)->first();
        $this->device_id = $device?->device_id;
    }

    // Close modal
    public function closeModal()
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('pulse::livewire.manage-water-tanks');
    }
}
