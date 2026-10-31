<?php

namespace App\Livewire\Pulse;

use App\Http\Controllers\Pulse\AssetCategoriesController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ManageAssetCategories extends Component
{
    public $categoriesId;

    public $categories = [];

    public $categoryName;

    public $is_active;

    public $confirmingToggle = false;

    public $confirmingCategoriesId = null;

    public $currentTeam;

    public $isModalOpen = false;

    public $editing = false;

    public $teamId;

    protected $assetCategoriesController;

    public function __construct()
    {
        $this->assetCategoriesController = new AssetCategoriesController;
    }

    public function mount()
    {
        abort_unless(Gate::allows('asset_categories.view'), 403);

        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->index();
    }

    public function index()
    {
        $response = $this->assetCategoriesController->index($this->teamId);
        $this->categories = $response->getData();
    }

    public function openCategoryModal()
    {
        $this->isModalOpen = true;
        if (! $this->editing) {
            $this->resetForm();
        }
    }

    public function closeCategoryModal()
    {
        $this->isModalOpen = false;
        $this->resetForm();
    }

    public function store()
    {
        if (! Gate::allows('asset_categories.create')) {
            session()->flash('error', 'You are not authorized to manage asset categories');

            return;
        }

        $data = ['name' => $this->categoryName];
        $response = $this->assetCategoriesController->store(request()->merge($data), $this->teamId);

        if ($response->status() === 201) {
            $this->resetForm();
            $this->index();
            $this->closeCategoryModal();
            session()->flash('success', 'Asset category created successfully');
        } elseif ($response->status() === 422) {
            $errors = (array) $response->getData()->error;
            session()->flash('error', $errors['name'][0] ?? 'Failed to create asset category');
        } else {
            session()->flash('error', 'Failed to create asset category');
        }
    }

    public function edit($id)
    {
        $response = $this->assetCategoriesController->edit($id, $this->teamId);
        if ($response->status() === 200) {
            $data = $response->getData();
            $this->editing = true;
            $this->isModalOpen = true;
            $this->categoriesId = $data->id;
            $this->categoryName = $data->name;
            $this->is_active = $data->is_active;
        } else {
            session()->flash('error', 'Failed to fetch asset category details');
        }
    }

    public function update()
    {
        if (! Gate::allows('asset_categories.edit')) {
            session()->flash('error', 'You are not authorized to manage asset categories');

            return;
        }

        $data = ['name' => $this->categoryName];
        $request = request()->merge($data);

        $response = $this->assetCategoriesController->update($request, $this->categoriesId, $this->teamId);
        if ($response->status() === 200) {
            $this->resetForm();
            $this->index();
            $this->closeCategoryModal();
            session()->flash('success', 'Asset category updated successfully');
        } elseif ($response->status() === 422) {
            $errors = (array) $response->getData()->error;
            session()->flash('error', $errors['name'][0] ?? 'Failed to update asset category');
        } else {
            session()->flash('error', 'Failed to update asset category');
        }
    }

    public function delete($id)
    {
        if (! Gate::allows('asset_categories.delete')) {
            session()->flash('error', 'You are not authorized to manage asset categories');

            return;
        }

        $response = $this->assetCategoriesController->destroy($id, $this->teamId);
        if ($response->status() === 200) {
            $this->index();
            session()->flash('success', 'Asset category deleted successfully');
        } elseif ($response->status() === 400) {
            session()->flash('error', 'Cannot delete category because it is referenced in assets');
        } else {
            session()->flash('error', 'Failed to delete asset category');
        }
    }

    public function confirmToggle($categoriesId)
    {
        $this->confirmingToggle = true;
        $this->confirmingCategoriesId = $categoriesId;
    }

    public function confirm()
    {
        if (! Gate::allows('asset_categories.edit')) {
            session()->flash('error', 'You are not authorized to manage asset categories');

            return;
        }

        $this->assetCategoriesController->toggleActive($this->confirmingCategoriesId);
        $this->confirmingToggle = false;
        $this->confirmingCategoriesId = null;
        $this->index();

        session()->flash('success', 'Category status updated successfully');
    }

    public function cancelToggle()
    {
        $this->confirmingToggle = false;
        $this->confirmingCategoriesId = null;
    }

    public function resetForm()
    {
        $this->categoryName = null;
        $this->editing = false;
        $this->categoriesId = null;
    }

    public function render()
    {
        return view('pulse::livewire.manage-asset-categories');
    }
}
