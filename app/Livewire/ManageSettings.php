<?php

namespace App\Livewire;

use App\Http\Controllers\SettingsController;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ManageSettings extends Component
{
    public $settings = [];

    public $isEditMode = false;

    public $value;

    public $text;

    public $options = [];

    public $settingKey = null;

    public $editId = null;

    public $teamId;

    public $isModelOpen = false;

    protected $settingsController;

    public function __construct()
    {
        $this->settingsController = new SettingsController;
    }

    public function mount()
    {
        abort_unless(Gate::allows('factory_customizations.view'), 403);

        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->index();
    }

    public function index()
    {
        $response = $this->settingsController->index(request(), $this->teamId);
        $this->settings = $response->getData();
    }

    public function openSettingsModal()
    {
        $this->isModelOpen = true;
        if (! $this->isEditMode) {
            $this->resetFields();
        }
    }

    public function closeModal()
    {
        $this->resetFields();
        $this->isModelOpen = false;
    }

    public function edit($id)
    {
        $response = $this->settingsController->edit($id, $this->teamId);
        if ($response->status() === 200) {
            $data = $response->getData();
            $this->text = $data->text;
            $this->value = $data->value;
            $this->settingKey = $data->key ?? null;
            $this->options = $data->options ? explode(',', $data->options) : [];
            $this->isEditMode = true;
            $this->editId = $id;
            $this->isModelOpen = true;
        } else {
            session()->flash('error', 'Failed to fetch unit details');
        }
    }

    public function update()
    {
        if (! Gate::allows('factory_customizations.edit')) {
            session()->flash('error', 'You are not authorized to update settings');

            return;
        }

        if (ProductionEntryPolicyKeys::isPolicyKey($this->settingKey)) {
            $this->validate([
                'value' => ['required', Rule::in(ProductionEntryPolicyKeys::dayOptionsList())],
            ]);
        }

        $data = [
            'value' => $this->value,
        ];

        $response = $this->settingsController->update(request()->merge($data), $this->editId, $this->teamId);
        if ($response->status() === 200) {
            session()->flash('success', 'Setting updated successfully');
            $this->closeModal();
            $this->index();
        } else {
            session()->flash('error', 'Failed to update setting');
        }
    }

    public function resetFields()
    {
        $this->options = [];
        $this->value = null;
        $this->settingKey = null;
        $this->editId = null;
        $this->isEditMode = false;
    }

    public function isEntryPolicyKey(?string $key): bool
    {
        return ProductionEntryPolicyKeys::isPolicyKey($key);
    }

    public function render()
    {
        return view('livewire.manage-settings');
    }
}
