<?php

namespace App\Livewire\Pulse;

use App\Http\Controllers\Pulse\DeviceController;
use App\Models\Pulse\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class ManageDevices extends Component
{
    use WithPagination;

    public $device_id;

    public $status = 'inactive';

    public $showModal = false;

    public $editMode = false;

    public $deviceId;

    public $devices = [];

    public $module;

    public $teamId;

    public $eb_units;

    public $currentUser;

    public $is_superadmin_or_admin = false;

    public $confirmingToggle = false;

    public $confirmingDeviceId = null;

    // Threshold Fields
    public $off_th = 10;

    public $idle_th;

    public $efficient_th;

    public $duration_device_msg;

    public $duration_device_missing;

    protected $deviceController;

    public function __construct()
    {
        $this->deviceController = new DeviceController;
    }

    public function mount()
    {
        abort_unless(Gate::allows('view-superadmin-settings'), 403);
        $this->currentUser = Auth::user();
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->index();
    }

    public function index()
    {
        $response = $this->deviceController->index($this->teamId);
        $this->devices = $response->getData();
    }

    public function store()
    {
        if (! Gate::allows('view-superadmin-settings')) {
            session()->flash('error', 'You are not authorized to manage devices');

            return;
        }

        // Non-superadmins cannot set device_id from input; always generate server-side.
        $deviceId = Auth::user()->isSuperadmin()
            ? $this->device_id
            : $this->generateUniqueDeviceId();

        $data = [
            'device_id' => $deviceId,
        ];
        $response = $this->deviceController->store(request()->merge($data), $this->teamId);
        if ($response->status() === 201) {
            $this->index();
            $this->showModal = false;
            $this->resetForm();
            session()->flash('success', 'Device created successfully');
        } else {
            session()->flash('error', 'Failed to create device');
        }
    }

    public function edit($id)
    {
        $response = $this->deviceController->edit($id, $this->teamId);
        if ($response->status() === 200) {
            $data = $response->getData();
            $this->device_id = $data->device_id;
            $this->deviceId = $data->device_id;
            $this->status = $data->status;
            $this->module = $data->module;
            $this->off_th = $data->off_th;
            $this->idle_th = $data->idle_th;
            $this->efficient_th = $data->efficient_th;
            $this->duration_device_msg = $data->duration_device_msg;
            $this->duration_device_missing = $data->duration_device_missing;
            $this->eb_units = $data->eb_units;
            $this->editMode = true;
            $this->showModal = true;
        } else {
            session()->flash('error', 'Failed to edit device');

        }
    }

    public function update()
    {
        if (! Gate::allows('view-superadmin-settings')) {
            session()->flash('error', 'You are not authorized to manage devices');

            return;
        }

        // Device ID is never editable; always keep the existing ID regardless of input.
        $data = [
            'device_id' => $this->deviceId,
            'status' => $this->status,
            'module' => $this->module,
            'off_th' => $this->off_th,
            'idle_th' => $this->idle_th,
            'efficient_th' => $this->efficient_th,
            'duration_device_msg' => $this->duration_device_msg,
            'duration_device_missing' => $this->duration_device_missing,
            'eb_units' => $this->eb_units,
        ];
        $response = $this->deviceController->update(request()->merge($data), $this->deviceId, $this->teamId);
        if ($response->status() === 200) {
            $this->index();
            $this->showModal = false;
            $this->resetForm();
            session()->flash('success', 'Device updated successfully');
        } else {
            session()->flash('error', 'Failed to update device');
        }
    }

    public function delete($id)
    {
        if (! Gate::allows('view-superadmin-settings')) {
            session()->flash('error', 'You are not authorized to manage devices');

            return;
        }

        $response = $this->deviceController->destroy($id, $this->teamId);
        if ($response->status() === 200) {
            session()->flash('success', 'Device deleted successfully');
            $this->index();
        } else {
            session()->flash('error', 'Failed to delete device');
        }
    }

    public function updatedModule()
    {
        // Re-renders the component to show/hide threshold fields based on the selected module.
    }

    public function activateDevice($deviceId)
    {
        if (! Gate::allows('view-superadmin-settings')) {
            session()->flash('error', 'You are not authorized to manage devices');

            return;
        }

        $device = Device::where('device_id', $deviceId)->first();
        if ($device) {
            $device->status = 'active';
            $device->save();
            $this->index();
            session()->flash('success', 'Device activated successfully');
        }
    }

    public function deactivateDevice()
    {
        if (! Gate::allows('view-superadmin-settings')) {
            session()->flash('error', 'You are not authorized to manage devices');

            return;
        }

        $device = Device::where('device_id', $this->confirmingDeviceId)->first();
        if ($device) {
            $device->status = 'inactive';
            $device->save();
            $this->index();
            $this->confirmingToggle = false;
            $this->confirmingDeviceId = null;
            session()->flash('success', 'Device deactivated successfully');
        }
    }

    public function confirmDeactivate($deviceId)
    {
        $this->confirmingDeviceId = $deviceId;
        $this->confirmingToggle = true;
    }

    public function addDeviceModal()
    {
        $this->resetForm();
        $this->device_id = $this->generateUniqueDeviceId();
        $this->showModal = true;
    }

    /**
     * Generate a unique device ID between 1 and 1000
     */
    private function generateUniqueDeviceId(): int
    {
        do {
            $deviceId = rand(1, 1000);
            $response = $this->deviceController->index($this->teamId);
            $devices = $response->getData();
            $exists = false;
            foreach ($devices as $device) {
                if ($device->device_id == $deviceId) {
                    $exists = true;
                    break;
                }
            }
        } while ($exists);

        return $deviceId;
    }

    private function resetForm()
    {
        $this->reset([
            'deviceId',
            'device_id',
            'status',
            'off_th',
            'idle_th',
            'efficient_th',
            'duration_device_msg',
            'duration_device_missing',
            'showModal',
            'editMode',
        ]);
    }

    public function closeModal()
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('pulse::livewire.manage-devices');
    }
}
