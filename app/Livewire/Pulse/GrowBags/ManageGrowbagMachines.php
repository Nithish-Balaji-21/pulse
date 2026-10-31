<?php

namespace App\Livewire\Pulse\GrowBags;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManageGrowbagMachines extends Component
{
    public $teamId;

    public $machines;

    // Form fields
    public $machineId;

    public $field_id;

    public $machine_name;

    public $bags_per_punch = 1;

    public $is_active = 1;

    public $showModal = false;

    public $editMode = false;

    public $showDeleteConfirmationDialog = false;

    public $deleteMachineId;

    public function mount()
    {
        $team = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $team || ! $team->has_growbags_module) {
            return redirect("/{$team->id}/dashboard");
        }

        $this->teamId = $team->id;
        $this->loadMachines();
    }

    public function loadMachines()
    {
        $this->machines = DB::table('pulse_master_growbag_machines')
            ->where('team_id', $this->teamId)
            ->orderBy('machine_name')
            ->get();
    }

    public function openCreateModal()
    {
        if (! Auth::user()->isSuperadmin()) {
            session()->flash('error', 'Only Superadmin can add growbag machines.');

            return;
        }

        $this->resetForm();
        $this->editMode = false;
        $this->showModal = true;
    }

    public function openEditModal($id)
    {
        $machine = DB::table('pulse_master_growbag_machines')->find($id);
        if (! $machine) {
            return;
        }

        $this->machineId = $machine->id;
        $this->field_id = $machine->field_id;
        $this->machine_name = $machine->machine_name;
        $this->bags_per_punch = $machine->bags_per_punch;
        $this->is_active = $machine->is_active;

        $this->editMode = true;
        $this->showModal = true;
    }

    public function save()
    {
        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage growbag machines');

            return;
        }

        $user = Auth::user();
        $isSuperadmin = $user->isSuperadmin();
        $isOwnerOnly = ! $isSuperadmin && $user->getCurrentTeamRole() === 'owner';

        if ($isOwnerOnly) {
            // Owner may only persist name + bags_per_punch; ignore technical field changes.
            $this->validate([
                'machine_name' => 'required|string|max:50',
                'bags_per_punch' => 'required|integer|min:1|max:99',
            ]);

            if (! $this->editMode) {
                session()->flash('error', 'Only Superadmin can add growbag machines.');

                return;
            }

            DB::table('pulse_master_growbag_machines')
                ->where('id', $this->machineId)
                ->where('team_id', $this->teamId)
                ->update([
                    'machine_name' => $this->machine_name,
                    'bags_per_punch' => $this->bags_per_punch,
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
            'bags_per_punch' => 'required|integer|min:1|max:99',
        ]);

        if ($this->editMode) {
            DB::table('pulse_master_growbag_machines')
                ->where('id', $this->machineId)
                ->where('team_id', $this->teamId)
                ->update([
                    'field_id' => $this->field_id,
                    'machine_name' => $this->machine_name,
                    'bags_per_punch' => $this->bags_per_punch,
                    'is_active' => $this->is_active,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);
            session()->flash('message', 'Machine updated successfully.');
        } else {
            DB::table('pulse_master_growbag_machines')->insert([
                'team_id' => $this->teamId,
                'device_id' => DB::table('pulse_devices')->where('team_id', $this->teamId)->value('device_id'),
                'field_id' => $this->field_id,
                'machine_name' => $this->machine_name,
                'bags_per_punch' => $this->bags_per_punch,
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

    public function confirmDeletion($id)
    {
        $this->deleteMachineId = $id;
        $this->showDeleteConfirmationDialog = true;
    }

    public function deleteMachine()
    {
        if (! Gate::allows('manage-factory-configuration')) {
            session()->flash('error', 'You are not authorized to manage growbag machines');

            return;
        }

        DB::table('pulse_master_growbag_machines')
            ->where('id', $this->deleteMachineId)
            ->where('team_id', $this->teamId)
            ->delete();

        session()->flash('message', 'Machine deleted successfully.');
        $this->showDeleteConfirmationDialog = false;
        $this->loadMachines();
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function closeDeleteModal()
    {
        $this->showDeleteConfirmationDialog = false;
    }

    private function resetForm()
    {
        $this->machineId = null;
        $this->field_id = '';
        $this->machine_name = '';
        $this->bags_per_punch = 1;
        $this->is_active = 1;
    }

    public function render()
    {
        return view('pulse::livewire.growbags.manage-growbag-machines');
    }
}
