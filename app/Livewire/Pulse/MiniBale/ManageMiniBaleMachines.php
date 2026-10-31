<?php

namespace App\Livewire\Pulse\MiniBale;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManageMiniBaleMachines extends Component
{
    public $teamId;

    public $machines;

    public $confirmingToggle = false;

    public $confirmingMachineId = null;

    // Form fields
    public $machineId;

    public $field_id;

    public $machine_name;

    public $is_active = 1;

    public $showModal = false;

    public $editMode = false;

    public function mount()
    {
        $team = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $team || ! $team->has_pith_module || ! $team->has_pith_module_bales) {
            return redirect("/{$team->id}/dashboard");
        }

        $this->teamId = $team->id;
        $this->loadMachines();
    }

    public function loadMachines()
    {
        $this->machines = DB::table('pulse_master_minibale_machines')
            ->where('team_id', $this->teamId)
            ->orderBy('machine_name')
            ->get();
    }

    public function openCreateModal()
    {
        if (! Auth::user()->isSuperadmin()) {
            session()->flash('error', 'Only Superadmin can add fibre bale machines.');

            return;
        }

        $this->resetForm();
        $this->editMode = false;
        $this->showModal = true;
    }

    public function openEditModal($id)
    {
        $machine = DB::table('pulse_master_minibale_machines')->find($id);
        if (! $machine) {
            return;
        }

        $this->machineId = $machine->id;
        $this->field_id = $machine->field_id;
        $this->machine_name = $machine->machine_name;
        $this->is_active = $machine->is_active;

        $this->editMode = true;
        $this->showModal = true;
    }

    public function save()
    {
        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage fibre bale machines');

            return;
        }

        $user = Auth::user();
        $isSuperadmin = $user->isSuperadmin();
        $isOwnerOnly = ! $isSuperadmin && $user->getCurrentTeamRole() === 'owner';

        if ($isOwnerOnly) {
            // Owner may only persist machine name; ignore technical field changes.
            $this->validate([
                'machine_name' => 'required|string|max:50',
            ]);

            if (! $this->editMode) {
                session()->flash('error', 'Only Superadmin can add fibre bale machines.');

                return;
            }

            DB::table('pulse_master_minibale_machines')
                ->where('id', $this->machineId)
                ->where('team_id', $this->teamId)
                ->update([
                    'machine_name' => $this->machine_name,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);

            session()->flash('message', 'Machine updated successfully.');
            $this->showModal = false;
            $this->loadMachines();

            return;
        }

        $this->validate([
            'field_id' => 'required|string|max:50',
            'machine_name' => 'required|string|max:50',
        ]);

        if ($this->editMode) {
            DB::table('pulse_master_minibale_machines')
                ->where('id', $this->machineId)
                ->where('team_id', $this->teamId)
                ->update([
                    'field_id' => $this->field_id,
                    'machine_name' => $this->machine_name,
                    'is_active' => $this->is_active,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);

            session()->flash('message', 'Machine updated successfully.');
        } else {
            // Prevent duplicate field_id for the same team
            $exists = DB::table('pulse_master_minibale_machines')
                ->where('team_id', $this->teamId)
                ->where('field_id', $this->field_id)
                ->exists();

            if ($exists) {
                $this->addError('field_id', 'This Field ID is already registered for your team.');

                return;
            }

            DB::table('pulse_master_minibale_machines')->insert([
                'team_id' => $this->teamId,
                'device_id' => DB::table('pulse_devices')->where('team_id', $this->teamId)->value('device_id'),
                'field_id' => $this->field_id,
                'machine_name' => $this->machine_name,
                'is_active' => $this->is_active,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            session()->flash('message', 'Machine added successfully.');
        }

        $this->showModal = false;
        $this->loadMachines();
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm()
    {
        $this->machineId = null;
        $this->field_id = '';
        $this->machine_name = '';
        $this->is_active = 1;
    }

    public function confirmToggle($id)
    {
        $this->confirmingMachineId = $id;
        $this->confirmingToggle = true;
    }

    public function toggleStatus()
    {
        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage fibre bale machines');

            return;
        }

        $id = $this->confirmingMachineId;
        $machine = DB::table('pulse_master_minibale_machines')
            ->where('id', $id)
            ->where('team_id', $this->teamId)
            ->first();

        if (! $machine) {
            $this->cancelToggle();

            return;
        }

        DB::table('pulse_master_minibale_machines')
            ->where('id', $id)
            ->where('team_id', $this->teamId)
            ->update([
                'is_active' => ! $machine->is_active,
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

        session()->flash('message', $machine->is_active
            ? 'Machine deactivated.'
            : 'Machine activated.'
        );

        $this->cancelToggle();
        $this->loadMachines();
    }

    public function cancelToggle()
    {
        $this->confirmingToggle = false;
        $this->confirmingMachineId = null;
    }

    public function render()
    {
        return view('pulse::livewire.minibale.manage-minibale-machines');
    }
}
