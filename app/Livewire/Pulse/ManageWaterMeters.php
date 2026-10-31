<?php

namespace App\Livewire\Pulse;

use App\Http\Controllers\Pulse\MasterWaterMeterController;
use App\Models\Pulse\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManageWaterMeters extends Component
{
    public $meters = [];

    public $meterDbId;

    public $device_id;

    public $field_id;

    public $consumption_name;

    public $showModal = false;

    public $editMode = false;

    public $teamId;

    // Field IDs and their labels
    public $meterTypes = [
        'd27' => 'Total Water',
        'd16' => 'Husk Water',
    ];

    protected $controller;

    public function __construct()
    {
        $this->controller = new MasterWaterMeterController;
    }

    public function mount()
    {
        // Auto-fill device_id for current team
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $device = Device::where('team_id', $this->teamId)->first();
        $this->device_id = $device?->device_id;

        $this->index();
    }

    /** Load all meters */
    public function index()
    {
        $response = $this->controller->index($this->teamId);
        $this->meters = $response->getData();
    }

    /** Open modal for adding meter */
    public function addMeterModal()
    {
        if (! Auth::user()->isSuperadmin()) {
            session()->flash('error', 'Only Superadmin can add water meters.');

            return;
        }

        $this->resetForm();
        $this->resetErrorBag();
        $this->showModal = true;
    }

    /** Store new meter */
    public function store()
    {
        if (! Auth::user()->isSuperadmin()) {
            session()->flash('error', 'Only Superadmin can add water meters');

            return;
        }

        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage water meters');

            return;
        }

        // Always resolve device_id from team; never accept UI input.
        $device = Device::where('team_id', $this->teamId)->first();
        $this->device_id = $device?->device_id;

        $data = [
            'device_id' => $this->device_id,
            'field_id' => $this->field_id,
            'consumption_name' => $this->consumption_name,
        ];

        $response = $this->controller->store(request()->merge($data), $this->teamId);

        if ($response->status() === 201) {
            $this->resetErrorBag();
            session()->flash('success', 'Water meter added successfully');
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

    /** Edit meter modal */
    public function edit($id)
    {
        $response = $this->controller->edit($id, $this->teamId);
        $this->resetErrorBag();

        if ($response->status() === 200) {
            $meter = $response->getData();
            $this->meterDbId = $meter->id;
            $this->device_id = $meter->device_id;
            $this->field_id = $meter->field_id;
            $this->consumption_name = $meter->consumption_name;
            $this->editMode = true;
            $this->showModal = true;
        } else {
            session()->flash('error', 'Failed to load meter for editing');
        }
    }

    /** Update existing meter */
    public function update()
    {
        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage water meters');

            return;
        }

        // On edit: only consumption_name may change; keep existing device_id and field_id.
        $existing = null;
        if ($this->meterDbId) {
            $response = $this->controller->edit($this->meterDbId, $this->teamId);
            if ($response->status() === 200) {
                $existing = $response->getData();
            }
        }

        $data = [
            'device_id' => $existing->device_id ?? $this->device_id,
            'field_id' => $existing->field_id ?? $this->field_id,
            'consumption_name' => $this->consumption_name,
        ];

        $response = $this->controller->update(request()->merge($data), $this->meterDbId, $this->teamId);

        if ($response->status() === 200) {
            $this->resetErrorBag();
            session()->flash('success', 'Water meter updated successfully');
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

    /** Delete meter */
    public function delete($id)
    {
        if (! Auth::user()->isSuperadmin()) {
            session()->flash('error', 'Only Superadmin can delete water meters');

            return;
        }

        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage water meters');

            return;
        }

        $response = $this->controller->destroy($id, $this->teamId);

        if ($response->status() === 200) {
            session()->flash('success', 'Water meter deleted successfully');
            $this->index();
        } else {
            session()->flash('error', 'Failed to delete water meter');
        }
    }

    /** Reset form fields */
    private function resetForm()
    {
        $this->reset([
            'meterDbId',
            'field_id',
            'consumption_name',
            'showModal',
            'editMode',
        ]);

        // Reset device_id to current team's device
        $device = Device::where('team_id', $this->teamId)->first();
        $this->device_id = $device?->device_id;
    }

    /** Close modal */
    public function closeModal()
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('pulse::livewire.manage-water-meters');
    }
}
