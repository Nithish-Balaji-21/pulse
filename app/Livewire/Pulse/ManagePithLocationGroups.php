<?php

namespace App\Livewire\Pulse;

use App\Http\Controllers\Pulse\PithLocationGroupsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManagePithLocationGroups extends Component
{
    public $groupId;

    public $groups = [];

    public $groupName;

    public $displayOrder = '';

    public $confirmingToggle = false;

    public $confirmingGroupId = null;

    public $isModalOpen = false;

    public $editing = false;

    public $teamId;

    protected $pithLocationGroupsController;

    public function __construct()
    {
        $this->pithLocationGroupsController = new PithLocationGroupsController;
    }

    public function mount()
    {
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->index();
    }

    public function index()
    {
        $response = $this->pithLocationGroupsController->index($this->teamId);
        $this->groups = $response->getData();
    }

    public function openGroupModal()
    {
        $this->isModalOpen = true;

        if (! $this->editing) {
            $this->resetForm();
        }
    }

    public function closeGroupModal()
    {
        $this->isModalOpen = false;
        $this->resetForm();
    }

    public function store()
    {
        if (! Gate::allows('pith_location_groups.create')) {
            session()->flash('error', 'You are not authorized to manage pith location groups');

            return;
        }

        $data = [
            'name' => $this->groupName,
            'display_order' => $this->normalizedDisplayOrder(),
        ];

        $response = $this->pithLocationGroupsController->store(request()->merge($data), $this->teamId);

        if ($response->status() === 201) {
            $this->resetForm();
            $this->index();
            $this->closeGroupModal();
            session()->flash('success', 'Group created successfully');
        } else {
            $error = (array) ($response->getData()->error ?? ['Failed to create group']);
            session()->flash('error', $error['name'][0] ?? 'Failed to create group');
        }
    }

    public function edit($id)
    {
        $response = $this->pithLocationGroupsController->edit($id, $this->teamId);

        if ($response->status() === 200) {
            $data = $response->getData();
            $this->editing = true;
            $this->isModalOpen = true;
            $this->groupId = $data->id;
            $this->groupName = $data->name;
            $this->displayOrder = ($data->display_order ?? 0) > 0 ? $data->display_order : '';
        } else {
            session()->flash('error', 'Failed to fetch group details');
        }
    }

    public function update()
    {
        if (! Gate::allows('pith_location_groups.edit')) {
            session()->flash('error', 'You are not authorized to manage pith location groups');

            return;
        }

        $data = [
            'name' => $this->groupName,
            'display_order' => $this->normalizedDisplayOrder(),
        ];

        $response = $this->pithLocationGroupsController->update(request()->merge($data), $this->groupId, $this->teamId);

        if ($response->status() === 200) {
            $this->resetForm();
            $this->index();
            $this->closeGroupModal();
            session()->flash('success', 'Group updated successfully');
        } else {
            $error = (array) ($response->getData()->error ?? ['Failed to update group']);
            session()->flash('error', $error['name'][0] ?? 'Failed to update group');
        }
    }

    public function delete($id)
    {
        if (! Gate::allows('pith_location_groups.delete')) {
            session()->flash('error', 'You are not authorized to manage pith location groups');

            return;
        }

        $response = $this->pithLocationGroupsController->destroy($id, $this->teamId);

        if ($response->status() === 200) {
            $this->index();
            session()->flash('success', 'Group deleted successfully');
        } elseif ($response->status() === 400) {
            session()->flash('error', 'Cannot delete this group while locations are still assigned to it');
        } else {
            session()->flash('error', 'Failed to delete group');
        }
    }

    public function confirmToggle($groupId)
    {
        $group = collect($this->groups)->firstWhere('id', $groupId);

        // Surface the block before opening the dialog; the controller re-checks on submit.
        if ($group && $group->is_active && ($group->active_pith_locations_count ?? 0) > 0) {
            session()->flash('error', 'Cannot deactivate "'.$group->name.'" while '.$group->active_pith_locations_count.' active location(s) are assigned to it.');

            return;
        }

        $this->confirmingToggle = true;
        $this->confirmingGroupId = $groupId;
    }

    public function toggleActive()
    {
        if (! Gate::allows('pith_location_groups.edit')) {
            session()->flash('error', 'You are not authorized to manage pith location groups');

            return;
        }

        $response = $this->pithLocationGroupsController->toggleActive($this->confirmingGroupId, $this->teamId);

        $this->confirmingToggle = false;
        $this->confirmingGroupId = null;
        $this->index();

        if ($response->status() === 200) {
            session()->flash('success', 'Group status updated successfully');
        } else {
            session()->flash('error', $response->getData()->message ?? 'Failed to update group status');
        }
    }

    public function cancelToggle()
    {
        $this->confirmingToggle = false;
        $this->confirmingGroupId = null;
    }

    public function resetForm()
    {
        $this->groupName = null;
        $this->displayOrder = '';
        $this->editing = false;
        $this->groupId = null;
    }

    protected function normalizedDisplayOrder(): int
    {
        return $this->displayOrder === '' || $this->displayOrder === null
            ? 0
            : max(0, (int) $this->displayOrder);
    }

    public function render()
    {
        return view('pulse::livewire.manage-pith-location-groups');
    }
}
