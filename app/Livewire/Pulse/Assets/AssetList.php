<?php

namespace App\Livewire\Pulse\Assets;

use App\Models\Pulse\Asset;
use App\Models\Pulse\AssetCategory;
use App\Models\Pulse\AssetOutward;
use App\Models\Pulse\AssetPurchase;
use App\Models\Pulse\AssetReturn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class AssetList extends Component
{
    use WithFileUploads;
    use WithPagination;

    public $perPage = 25;

    public $search = '';

    public $filterCategoryId = null;

    public $filterState = '1';

    public $teamId;

    public $categories;

    public $isModalOpen = false;

    public $editing = false;

    public $assetId = null;

    public $assetName = '';

    public $assetCategoryId = null;

    public $state = 1;

    public $remarks = '';

    // Attachments handling
    public $allAttachments = '';

    public $existingAttachments = [];

    public $newAttachments = [];

    public $uploadedFiles;

    public $showViewModal = false;

    public $selectedAsset = null;

    public function mount()
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        abort_unless(Gate::allows('view-assets'), 403);
        $this->teamId = $currentTeam->id;
        $this->categories = AssetCategory::where('team_id', $this->teamId)
            ->where('is_active', 1)
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedFilterCategoryId()
    {
        $this->resetPage();
    }

    public function updatedFilterState()
    {
        $this->resetPage();
    }

    public function openModal()
    {
        $this->isModalOpen = true;
        if (! $this->editing) {
            $this->resetForm();
        }
        $this->resetValidation();
    }

    public function closeModal()
    {
        $this->isModalOpen = false;
        $this->resetForm();
        $this->resetValidation();
    }

    public function store()
    {
        if (! Gate::allows('asset_tracking.create')) {
            session()->flash('error', 'You are not authorized to add asset');

            return;
        }
        $data = $this->validate([
            'assetName' => [
                'required', 'string', 'max:50',
                Rule::unique('pulse_assets', 'asset_name')->where(fn ($q) => $q->where('team_id', $this->teamId)),
            ],
            'assetCategoryId' => 'required|exists:pulse_asset_categories,id',
            'state' => 'required|in:0,1',
            'remarks' => 'nullable|string',
        ], [
            'assetName.required' => 'Asset name is required',
            'assetName.unique' => 'An asset with this name already exists for your team.',
            'assetCategoryId.required' => 'Asset category is required',
        ]);

        try {
            // handle attachments: combine existing + newly uploaded
            $allAttachments = $this->allAttachments ? json_decode($this->allAttachments) : [];
            if ($this->newAttachments) {
                foreach ($this->newAttachments as $attachment) {
                    $path = $attachment->store('attachments', 'public');
                    $allAttachments[] = $path;
                }
            }

            Asset::create([
                'team_id' => $this->teamId,
                'asset_category_id' => $this->assetCategoryId,
                'asset_name' => $this->assetName,
                'state' => (int) $this->state,
                'remarks' => $this->remarks,
                'attachments' => $allAttachments ? json_encode($allAttachments, JSON_UNESCAPED_SLASHES) : '',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            session()->flash('error', 'Duplicate asset name for this team.');

            return;
        }

        $this->closeModal();
        session()->flash('success', 'Asset created successfully');
    }

    public function edit($id)
    {
        $asset = Asset::where('team_id', $this->teamId)->find($id);
        if (! $asset) {
            session()->flash('error', 'Asset not found');

            return;
        }
        $this->editing = true;
        $this->isModalOpen = true;
        $this->assetId = $asset->id;
        $this->assetName = $asset->asset_name;
        $this->assetCategoryId = $asset->asset_category_id;
        $this->state = $asset->state;
        $this->remarks = $asset->remarks;
        // load existing attachments if any
        $this->existingAttachments = $asset->attachments ? json_decode($asset->attachments, true) : [];
        $this->allAttachments = $asset->attachments;

        $this->resetValidation();
    }

    public function update()
    {
        if (! Gate::allows('asset_tracking.edit')) {
            session()->flash('error', 'You are not authorized to update asset');

            return;
        }
        $data = $this->validate([
            'assetName' => [
                'required', 'string', 'max:50',
                Rule::unique('pulse_assets', 'asset_name')
                    ->where(fn ($q) => $q->where('team_id', $this->teamId))
                    ->ignore($this->assetId, 'id'),
            ],
            'assetCategoryId' => 'required|exists:pulse_asset_categories,id',
            'state' => 'required|in:0,1',
            'remarks' => 'nullable|string',
        ], [
            'assetName.required' => 'Asset name is required',
            'assetName.unique' => 'An asset with this name already exists for your team.',
            'assetCategoryId.required' => 'Asset category is required',
        ]);

        $asset = Asset::where('team_id', $this->teamId)->find($this->assetId);
        if (! $asset) {
            session()->flash('error', 'Asset not found');

            return;
        }

        try {
            // handle attachments: combine existing + newly uploaded
            $allAttachments = $this->allAttachments ? json_decode($this->allAttachments) : [];
            if ($this->newAttachments) {
                foreach ($this->newAttachments as $attachment) {
                    $path = $attachment->store('attachments', 'public');
                    $allAttachments[] = $path;
                }
            }

            $asset->update([
                'asset_name' => $this->assetName,
                'asset_category_id' => $this->assetCategoryId,
                'state' => (int) $this->state,
                'remarks' => $this->remarks,
                'attachments' => $allAttachments ? json_encode($allAttachments, JSON_UNESCAPED_SLASHES) : '',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            session()->flash('error', 'Duplicate asset name for this team.');

            return;
        }

        $this->closeModal();
        session()->flash('success', 'Asset updated successfully');
    }

    public function delete($id)
    {
        if (! Gate::allows('asset_tracking.delete')) {
            session()->flash('error', 'You are not authorized to delete asset');

            return;
        }
        $asset = Asset::where('team_id', $this->teamId)->find($id);
        if (! $asset) {
            session()->flash('error', 'Asset not found');

            return;
        }
        $hasPurchases = AssetPurchase::where('asset_id', $asset->id)->exists();
        if ($hasPurchases) {
            session()->flash('error', "This asset can't be deleted since it has purchase entries");

            return;
        }
        $asset->delete();
        session()->flash('success', 'Asset deleted successfully');
    }

    public function view($id)
    {
        $this->selectedAsset = Asset::with('assetCategory')
            ->where('team_id', $this->teamId)
            ->find($id);
        $this->showViewModal = (bool) $this->selectedAsset;
    }

    public function closeViewModal()
    {
        $this->showViewModal = false;
        $this->selectedAsset = null;
    }

    public function resetForm()
    {
        $this->editing = false;
        $this->assetId = null;
        $this->assetName = '';
        $this->assetCategoryId = null;
        $this->state = 1;
        $this->remarks = '';
        $this->allAttachments = '';
        $this->existingAttachments = [];
        $this->newAttachments = [];
        $this->uploadedFiles = null;
    }

    public function updatedUploadedFiles()
    {
        // validate file types and size
        $this->validate([
            'uploadedFiles' => 'file|mimes:jpg,jpeg,png,gif,tiff,svg|max:500',
        ], [
            'uploadedFiles.mimes' => 'Only image files (jpg, png, gif, tiff, svg) are allowed.',
            'uploadedFiles.max' => 'Each image must not be larger than 500 KB.',
        ]);

        $existingCount = $this->allAttachments ? count(json_decode($this->allAttachments)) : 0;
        $newCount = count($this->newAttachments);
        // Only allow 1 image in total
        if ($existingCount + $newCount >= 1) {
            $this->addError('uploadedFiles', 'You cannot upload more than 1 image.');
            $this->uploadedFiles = null;

            return;
        }

        if ($this->uploadedFiles) {
            $this->newAttachments = [$this->uploadedFiles];
            $this->uploadedFiles = null;
        }
    }

    public function removeNewAttachment($index)
    {
        unset($this->newAttachments[$index]);
        $this->newAttachments = array_values($this->newAttachments);
    }

    public function removeExistingAttachment($index)
    {
        if (! isset($this->existingAttachments[$index])) {
            return;
        }

        $path = $this->existingAttachments[$index];

        // delete file from storage if exists
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        unset($this->existingAttachments[$index]);
        $this->existingAttachments = array_values($this->existingAttachments);

        // update the JSON backing field
        $this->allAttachments = ! empty($this->existingAttachments) ? json_encode($this->existingAttachments, JSON_UNESCAPED_SLASHES) : '';
    }

    public function render()
    {
        $assets = Asset::with('assetCategory')
            ->where('team_id', $this->teamId)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('asset_name', 'like', '%'.$this->search.'%')
                        ->orWhereHas('assetCategory', function ($q2) {
                            $q2->where('name', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->filterCategoryId, function ($query) {
                $query->where('asset_category_id', $this->filterCategoryId);
            })
            ->when($this->filterState !== '', function ($query) {
                $query->where('state', (int) $this->filterState);
            })
            ->orderBy('asset_name', 'asc')
            ->paginate($this->perPage);

        foreach ($assets as $asset) {
            $purchasedTotal = (int) AssetPurchase::where('asset_id', $asset->id)->sum('purchased_quantity');
            $outwardTotal = (int) AssetOutward::where('asset_id', $asset->id)->sum('outward_quantity');
            $returnedTotal = (int) AssetReturn::whereIn('outward_id', AssetOutward::where('asset_id', $asset->id)->pluck('outward_id'))
                ->sum('returned_quantity');
            $asset->purchased_total = $purchasedTotal;
            $asset->outward_total = $outwardTotal;
            $asset->in_stock = ($purchasedTotal + $returnedTotal) - $outwardTotal;
        }

        return view('pulse::livewire.assets.asset-list', compact('assets'));
    }
}
