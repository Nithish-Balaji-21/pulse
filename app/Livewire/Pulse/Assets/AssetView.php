<?php

namespace App\Livewire\Pulse\Assets;

use App\Models\Pulse\Asset;
use App\Models\Pulse\AssetOutward;
use App\Models\Pulse\AssetPurchase;
use App\Models\Pulse\AssetReturn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class AssetView extends Component
{
    public int $assetId;

    public ?int $teamId = null;

    public ?Asset $asset = null;

    public string $activeTab = 'purchased';

    public int $totalPurchased = 0;

    public int $totalOutward = 0;

    public int $totalReturned = 0;

    public int $inStock = 0;

    public $purchases = [];

    public $outwards = [];

    public $returns = [];

    public bool $showPurchaseModal = false;

    public bool $showOutwardModal = false;

    public bool $showReturnModal = false;

    // image modal
    public bool $showImageModal = false;

    public ?string $imageUrl = null;

    public ?int $purchaseId = null;

    public ?int $outwardId = null;

    public ?int $returnId = null;

    public function mount($id, $tab = null)
    {
        abort_unless(Gate::allows('view-assets'), 403);
        $this->assetId = (int) $id;
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->activeTab = in_array($tab, ['purchased', 'outward', 'returned']) ? $tab : 'purchased';
        $this->loadData();
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['purchased', 'outward', 'returned']) ? $tab : 'purchased';
    }

    public function openPurchaseCreate(): void
    {
        $this->purchaseId = null;
        $this->showPurchaseModal = true;
        $this->activeTab = 'purchased';
    }

    public function openPurchaseEdit(int $purchaseId): void
    {
        $this->purchaseId = $purchaseId;
        $this->showPurchaseModal = true;
        $this->activeTab = 'purchased';
    }

    public function closePurchaseModal(): void
    {
        $this->showPurchaseModal = false;
        $this->purchaseId = null;
    }

    public function openOutwardCreate(): void
    {
        $this->outwardId = null;
        $this->showOutwardModal = true;
        $this->activeTab = 'outward';
    }

    public function openOutwardEdit(int $outwardId): void
    {
        $this->outwardId = $outwardId;
        $this->showOutwardModal = true;
        $this->activeTab = 'outward';
    }

    public function closeOutwardModal(): void
    {
        $this->showOutwardModal = false;
        $this->outwardId = null;
    }

    public function openReturnCreate(int $outwardId): void
    {
        $this->returnId = null;
        $this->outwardId = $outwardId;
        $this->showReturnModal = true;
        $this->activeTab = 'returned';
    }

    public function openReturnEdit(int $returnId): void
    {
        $this->returnId = $returnId;
        $this->showReturnModal = true;
        $this->activeTab = 'returned';
    }

    public function closeReturnModal(): void
    {
        $this->showReturnModal = false;
        $this->returnId = null;
        $this->outwardId = null;
    }

    private function loadData(): void
    {
        $this->asset = Asset::with('assetCategory')->findOrFail($this->assetId);

        $this->totalPurchased = (int) AssetPurchase::where('asset_id', $this->assetId)->sum('purchased_quantity');
        $this->totalOutward = (int) AssetOutward::where('asset_id', $this->assetId)->sum('outward_quantity');
        $this->totalReturned = (int) AssetReturn::whereIn('outward_id', function ($q) {
            $q->select('outward_id')->from('pulse_asset_outwards')->where('asset_id', $this->assetId);
        })->sum('returned_quantity');

        // In Stock = (Purchased + Returned) - Outward
        $this->inStock = ($this->totalPurchased + $this->totalReturned) - $this->totalOutward;

        $this->purchases = AssetPurchase::where('asset_id', $this->assetId)
            ->orderBy('purchase_date', 'desc')
            ->get();

        $this->outwards = AssetOutward::with('returns')
            ->where('asset_id', $this->assetId)
            ->orderBy('outward_date', 'desc')
            ->get();

        $this->returns = AssetReturn::whereIn('outward_id', function ($q) {
            $q->select('outward_id')->from('pulse_asset_outwards')->where('asset_id', $this->assetId);
        })
            ->orderBy('returned_date', 'desc')
            ->get();
    }

    public function openImageModal(string $path): void
    {
        $this->imageUrl = Storage::url($path);
        $this->showImageModal = true;
    }

    public function closeImageModal(): void
    {
        $this->showImageModal = false;
        $this->imageUrl = null;
    }

    public function render()
    {
        return view('pulse::livewire.assets.asset-view');
    }

    public function deletePurchase(int $assetPurchaseId): void
    {
        if (! Gate::allows('asset_tracking.delete')) {
            session()->flash('error', 'You are not authorized to delete purchase records');

            return;
        }

        $purchase = AssetPurchase::find($assetPurchaseId);
        if (! $purchase || $purchase->asset_id !== $this->assetId) {
            session()->flash('error', 'Purchase record not found');

            return;
        }

        $currentPurchasedTotal = (int) AssetPurchase::where('asset_id', $this->assetId)->sum('purchased_quantity');
        $currentOutwardTotal = (int) AssetOutward::where('asset_id', $this->assetId)->sum('outward_quantity');
        $newPurchasedTotal = $currentPurchasedTotal - (int) $purchase->purchased_quantity;

        if ($newPurchasedTotal < $currentOutwardTotal) {
            session()->flash('error', 'Error: Purchase quantity ('.$newPurchasedTotal.') cannot be less than the Outward quantity ('.$currentOutwardTotal.').');

            return;
        }

        $purchase->delete();
        session()->flash('success', 'Purchase deleted successfully');
        $this->loadData();
    }

    public function deleteOutward(int $outwardId): void
    {
        if (! Gate::allows('asset_tracking.delete')) {
            session()->flash('error', 'You are not authorized to delete outward records');

            return;
        }

        $outward = AssetOutward::withCount('returns')->find($outwardId);
        if (! $outward || $outward->asset_id !== $this->assetId) {
            session()->flash('error', 'Outward record not found');

            return;
        }

        if ($outward->returns_count > 0) {
            session()->flash('error', 'Cannot delete: This outward has return entries.');

            return;
        }

        $outward->delete();
        session()->flash('success', 'Outward deleted successfully');
        $this->loadData();
    }

    public function deleteReturn(int $returnId): void
    {
        if (! Gate::allows('asset_tracking.delete')) {
            session()->flash('error', 'You are not authorized to delete return records');

            return;
        }

        $return = AssetReturn::find($returnId);
        if (! $return) {
            session()->flash('error', 'Return record not found');

            return;
        }
        // verify that return belongs to current asset
        $outward = AssetOutward::find($return->outward_id);
        if (! $outward || $outward->asset_id !== $this->assetId) {
            session()->flash('error', 'Return record not found for this asset');

            return;
        }

        $return->delete();
        session()->flash('success', 'Return deleted successfully');
        $this->loadData();
    }

    protected $listeners = [
        'close-purchase-modal' => 'closePurchaseModal',
        'close-outward-modal' => 'closeOutwardModal',
        'close-return-modal' => 'closeReturnModal',
        'purchase-saved' => 'onPurchaseSaved',
        'outward-saved' => 'onOutwardSaved',
        'return-saved' => 'onReturnSaved',
    ];

    public function onPurchaseSaved(): void
    {
        $this->closePurchaseModal();
        $this->loadData();
        $this->activeTab = 'purchased';
    }

    public function onOutwardSaved(): void
    {
        $this->closeOutwardModal();
        $this->loadData();
        $this->activeTab = 'outward';
    }

    public function onReturnSaved(): void
    {
        $this->closeReturnModal();
        $this->loadData();
        $this->activeTab = 'returned';
    }
}
