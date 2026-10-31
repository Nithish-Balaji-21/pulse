<?php

namespace App\Livewire;

use App\Http\Controllers\StaffsController;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManageStaff extends Component
{
    public $teamId;

    public $staffs = [];

    public $confirmingToggle = false;

    public $confirmingStaffId = null;

    public $staff_id;

    public $staff_name;

    public $is_active;

    public $salary_per_hr;

    public $showModal = false; // Controls the modal display

    public $editMode = false;

    protected $staffsController;

    public function __construct()
    {

        $this->staffsController = new StaffsController;

    }

    public function mount()
    {
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->index();
    }

    public function index()
    {
        $response = $this->staffsController->index($this->teamId);
        $this->staffs = $response->getData();
    }

    public function store()
    {
        if (! Gate::allows('manage_staff.create')) {
            session()->flash('error', 'You are not authorized to add staff');

            return;
        }

        $data = [
            'staff_name' => $this->staff_name,
            'salary_per_hr' => $this->salary_per_hr,
        ];
        $response = $this->staffsController->store(request()->merge($data), $this->teamId);
        if ($response->status() === 201) {
            $this->closeForm();
            $this->index();
            session()->flash('success', 'Staff added successfully');
        } elseif ($response->status() === 500) {
            $errors = $response->getData()->error;
            session()->flash('error', implode(', ', Arr::flatten($errors)));
        }
    }

    public function openForm($id = null)
    {
        if ($id) {
            $response = $this->staffsController->edit($id, $this->teamId);
            if ($response->status() === 200) {
                $data = $response->getData();
                $this->staff_id = $data->id;
                $this->staff_name = $data->staff_name;
                $this->salary_per_hr = $data->salary_per_hr;
                $this->is_active = $data->is_active;
                $this->editMode = true;
                $this->showModal = true;
            } elseif ($response->status() === 403) {
                session()->flash('error', 'You are not authorized to edit staff.');
            } else {
                session()->flash('error', 'Unable to load staff details for editing.');
            }

        } else {
            $this->staff_id = null;
            $this->staff_name = '';
            $this->salary_per_hr = '';
            $this->is_active = true;
            $this->editMode = false;
            $this->showModal = true;
        }
    }

    public function update()
    {
        if (! Gate::allows('manage_staff.edit')) {
            session()->flash('error', 'You are not authorized to update staff');

            return;
        }
        $data = [
            'staff_name' => $this->staff_name,
            'salary_per_hr' => $this->salary_per_hr,
        ];
        $request = request()->merge($data);
        $response = $this->staffsController->update($request, $this->staff_id, $this->teamId);
        if ($response->status() === 200) {
            $this->closeForm();
            $this->index();
            session()->flash('success', 'Staff updated successfully');
        } elseif ($response->status() === 500) {
            $errors = $response->getData()->error;
            session()->flash('error', implode(', ', Arr::flatten($errors)));
        } else {
            session()->flash('error', 'An unexpected error occurred.');
        }
    }

    public function delete($id)
    {
        if (! Gate::allows('manage_staff.delete')) {
            session()->flash('error', 'You are not authorized to delete staff');

            return;
        }

        $response = $this->staffsController->destroy($id, $this->teamId);
        if ($response->status() === 200) {
            session()->flash('success', 'Staff deleted successfully');
            $this->index();
        } elseif ($response->status() === 404) {
            session()->flash('error', 'Unauthorized or resource does not exist');
        } elseif ($response->status() === 422) {
            session()->flash('error', 'Staff is being used in other records. If you want, you can deactivate the staff.');
        } else {
            session()->flash('error', 'Failed to delete staff');
        }
    }

    public function closeForm()
    {
        $this->resetForm();
        $this->showModal = false;
    }

    public function resetForm()
    {
        $this->reset(['staff_id', 'staff_name', 'salary_per_hr', 'is_active']);
    }

    public function confirmToggle($id)
    {
        $this->confirmingStaffId = $id;
        $this->confirmingToggle = true;
    }

    public function toggleStatus()
    {
        if (! Gate::allows('manage_staff.edit')) {
            session()->flash('error', 'You are not authorized to update staff status');

            return;
        }

        $response = $this->staffsController->toggleActivity($this->confirmingStaffId, $this->teamId);
        if ($response->status() === 200) {
            $this->cancelToggle();
            $this->index();
            session()->flash('success', 'Staff status updated successfully');
        } else {
            session()->flash('error', 'Failed to update staff status');
        }
    }

    public function cancelToggle()
    {
        $this->confirmingToggle = false;
        $this->confirmingStaffId = null;
    }

    public function render()
    {
        return view('livewire.manage-staff');
    }
}
