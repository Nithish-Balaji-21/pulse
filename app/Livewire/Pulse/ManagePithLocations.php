<?php

namespace App\Livewire\Pulse;

use App\Http\Controllers\Pulse\PithLocationGroupsController;
use App\Http\Controllers\Pulse\PithLocationsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManagePithLocations extends Component
{
    public $pithLocationId;

    public $pithLocations = [];

    public $pithLocationGroups = [];

    public $pithLocationName;

    public $groupId = '';

    public $similarNameWarning = null;

    public $confirmedSimilarName = null;

    public $lowStockAlertValue = '';

    public $is_active = false;

    public $confirmingToggle = false;

    public $confirmingLocationId = null;

    public $currentTeam;

    public $isModalOpen = false;

    public $editing = false;

    public $teamId;

    protected $pithLocationController;

    protected $pithLocationGroupsController;

    public function __construct()
    {
        $this->pithLocationController = new PithLocationsController;
        $this->pithLocationGroupsController = new PithLocationGroupsController;
    }

    public function mount()
    {
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->index();
    }

    public function index()
    {
        $response = $this->pithLocationController->index($this->teamId);
        $this->pithLocations = $response->getData();

        // Inactive groups stay selectable so editing a location already assigned to one
        // does not silently reset it to ungrouped.
        $this->pithLocationGroups = $this->pithLocationGroupsController->index($this->teamId)->getData();
    }

    public function openPithLocationModal()
    {
        $this->isModalOpen = true;

        if (! $this->editing) {
            $this->resetForm();
        }
    }

    public function closePithLocationModal()
    {
        $this->isModalOpen = false;
        $this->resetForm();
    }

    /**
     * Clearing the warning as soon as the name changes means a corrected name is
     * re-checked rather than sailing through on the earlier confirmation.
     */
    public function updatedPithLocationName()
    {
        $this->similarNameWarning = null;
        $this->confirmedSimilarName = null;
    }

    /**
     * Names are only compared within a group, so switching group changes the
     * comparison set and any earlier verdict no longer applies.
     */
    public function updatedGroupId()
    {
        $this->similarNameWarning = null;
        $this->confirmedSimilarName = null;
    }

    /**
     * Near-duplicates are discouraged rather than forbidden — some factories
     * genuinely number their beds — so the first save explains the clash and the
     * second one goes through.
     */
    protected function blockedBySimilarName(): bool
    {
        $name = (string) $this->pithLocationName;

        if ($name === '' || $this->confirmedSimilarName === $name) {
            return false;
        }

        $similar = PithLocationsController::findSimilarName(
            $name,
            $this->teamId,
            $this->pithLocationId,
            $this->normalizedGroupId()
        );

        if ($similar === null) {
            return false;
        }

        $this->similarNameWarning = '"'.$similar.'" is already in this group and reads almost the same. Give this location a name that says what it is — the group already names the site. Save again to keep the name anyway.';
        $this->confirmedSimilarName = $name;

        return true;
    }

    public function store()
    {
        if (! Gate::allows('pith_locations.create')) {
            session()->flash('error', 'You are not authorized to manage pith locations');

            return;
        }

        if ($this->blockedBySimilarName()) {
            return;
        }

        $data = [
            'name' => $this->pithLocationName,
            'group_id' => $this->normalizedGroupId(),
            'low_stock_alert_value' => $this->normalizedLowStockAlertValue(),
        ];
        $response = $this->pithLocationController->store(request()->merge($data), $this->teamId);
        if ($response->status() === 201) {
            $this->resetForm();
            $this->index();
            $this->closePithLocationModal();
            session()->flash('success', 'Pith Location created successfully');
        } else {
            $error = (array) ($response->getData()->error ?? ['Failed to update Pith Location']);
            session()->flash('error', $error['name'][0] ?? 'Failed to update Pith Location');
        }
    }

    public function edit($id)
    {
        $response = $this->pithLocationController->edit($id, $this->teamId);

        if ($response->status() === 200) {
            $data = $response->getData();
            $this->editing = true;
            $this->isModalOpen = true;
            $this->pithLocationId = $data->id;
            $this->pithLocationName = $data->name;
            $this->groupId = $data->group_id ? (string) $data->group_id : '';
            $this->lowStockAlertValue = ($data->low_stock_alert_value ?? 0) > 0
                ? $data->low_stock_alert_value
                : '';
        } else {
            session()->flash('error', 'Failed to fetch Pith Location details');
        }
    }

    public function update()
    {
        if (! Gate::allows('pith_locations.edit')) {
            session()->flash('error', 'You are not authorized to manage pith locations');

            return;
        }

        if ($this->blockedBySimilarName()) {
            return;
        }

        $data = [
            'name' => $this->pithLocationName,
            'group_id' => $this->normalizedGroupId(),
            'low_stock_alert_value' => $this->normalizedLowStockAlertValue(),
        ];
        $response = $this->pithLocationController->update(request()->merge($data), $this->pithLocationId, $this->teamId);
        if ($response->status() === 200) {
            $this->resetForm();
            $this->index();
            $this->closePithLocationModal();
            session()->flash('success', 'Pith Location updated successfully');
        } else {
            $error = (array) ($response->getData()->error ?? ['Failed to update Pith Location']);
            session()->flash('error', $error['name'][0] ?? 'Failed to update Pith Location');
        }
    }

    public function delete($id)
    {
        if (! Gate::allows('pith_locations.delete')) {
            session()->flash('error', 'You are not authorized to manage pith locations');

            return;
        }

        $response = $this->pithLocationController->destroy($id, $this->teamId);
        if ($response->status() === 200) {
            $this->index();
            session()->flash('success', 'Pith Location deleted successfully');
        } elseif ($response->status() === 401) {
            session()->flash('error', 'Cannot delete location because it is referenced in pith loads');
        } else {
            session()->flash('error', 'This location has loads So cannot be deleted');
        }
    }

    public function confirmToggle($pithLocationId)
    {
        $location = collect($this->pithLocations)->firstWhere('id', $pithLocationId);

        // Surface the block before opening the dialog; the controller re-checks on submit.
        if ($location && $location->is_active && (float) ($location->remaining_cft ?? 0) > 0) {
            session()->flash('error', '"'.$location->name.'" still holds '.rtrim(rtrim(number_format((float) $location->remaining_cft, 2), '0'), '.').' cft. Empty the location before deactivating it.');

            return;
        }

        $this->confirmingToggle = true;
        $this->confirmingLocationId = $pithLocationId;
    }

    public function toggleActive()
    {
        if (! Gate::allows('pith_locations.edit')) {
            session()->flash('error', 'You are not authorized to manage pith locations');

            return;
        }

        $response = $this->pithLocationController->toggleActive($this->confirmingLocationId, $this->teamId);

        $this->confirmingToggle = false;
        $this->confirmingLocationId = null; // Reset confirming state
        $this->index();

        if ($response->status() === 200) {
            session()->flash('success', 'Location status updated successfully');
        } else {
            session()->flash('error', $response->getData()->message ?? 'Failed to update location status');
        }
    }

    public function cancelToggle()
    {
        $this->confirmingToggle = false;
        $this->confirmingLocationId = null; // Reset confirming state
    }

    public function resetForm()
    {
        $this->pithLocationName = null;
        $this->groupId = '';
        $this->similarNameWarning = null;
        $this->confirmedSimilarName = null;
        $this->lowStockAlertValue = '';
        $this->editing = false;
        $this->pithLocationId = null;
    }

    protected function normalizedLowStockAlertValue(): ?int
    {
        return PithLocationsController::normalizeLowStockAlertValue($this->lowStockAlertValue);
    }

    protected function normalizedGroupId(): ?int
    {
        return PithLocationsController::normalizeGroupId($this->groupId, $this->teamId);
    }

    public function render()
    {
        return view('pulse::livewire.manage-pith-locations');
    }
}
