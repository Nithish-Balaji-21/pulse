<?php

namespace App\Livewire\Pulse\Assets;

use App\Models\Pulse\Asset;
use App\Models\Pulse\AssetOutward;
use App\Models\Pulse\AssetPurchase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class AssetPurchaseCreateAndEdit extends Component
{
    public $teamId;

    public ?int $assetId = null;

    public ?int $purchaseId = null;

    public ?Asset $asset = null;

    public bool $editing = false;

    public bool $asModal = false;

    public string $purchase_date = '';

    public string $from_company = '';

    public $price = null;

    public string $purchase_type = 'Purchased';

    public int $purchased_quantity = 0;

    public ?string $remarks = null;

    public function mount($assetId = null, $purchaseId = null, $asModal = false)
    {
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->assetId = $assetId ? (int) $assetId : null;
        $this->purchaseId = $purchaseId ? (int) $purchaseId : null;
        $this->asModal = (bool) $asModal;

        if ($this->purchaseId) {
            $this->editing = true;
            $purchase = AssetPurchase::findOrFail($this->purchaseId);
            $this->assetId = (int) $purchase->asset_id;
            $this->asset = Asset::findOrFail($this->assetId);

            $this->purchase_date = (string) $purchase->purchase_date;
            $this->from_company = (string) $purchase->from_company;
            $this->price = $purchase->price;
            $this->purchase_type = (string) $purchase->purchase_type;
            $this->purchased_quantity = (int) $purchase->purchased_quantity;
            $this->remarks = $purchase->remarks;
        } else {
            $this->editing = false;
            $this->asset = Asset::findOrFail((int) $this->assetId);
        }
    }

    protected function rules()
    {
        return [
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'from_company' => ['required', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'purchase_type' => ['required', Rule::in(['Purchased', 'Loaned'])],
            'purchased_quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function save()
    {
        $this->validate();

        AssetPurchase::create([
            'asset_id' => (int) $this->assetId,
            'purchase_type' => $this->purchase_type,
            'purchase_date' => $this->purchase_date,
            'from_company' => $this->from_company,
            'purchased_quantity' => $this->purchased_quantity,
            'price' => $this->price,
            'remarks' => $this->remarks,
        ]);
        if ($this->asModal) {
            $this->dispatch('purchase-saved');

            return;
        }

        return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'purchased']);
    }

    public function update()
    {
        $this->validate();

        $purchase = AssetPurchase::find($this->purchaseId);
        if (! $purchase) {
            session()->flash('error', 'Purchase record not found');

            return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'purchased']);
        }

        // Ensure total purchased after update is not less than total outward
        $sumOtherPurchases = (int) AssetPurchase::where('asset_id', $this->assetId)
            ->where('asset_purchase_id', '!=', $this->purchaseId)
            ->sum('purchased_quantity');
        $currentOutwardTotal = (int) AssetOutward::where('asset_id', $this->assetId)->sum('outward_quantity');
        $newPurchasedTotal = $sumOtherPurchases + (int) $this->purchased_quantity;

        if ($newPurchasedTotal < $currentOutwardTotal) {
            session()->flash('error', 'Cannot update: Purchased total would be less than total outward.');

            return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'purchased']);
        }

        $purchase->update([
            'purchase_type' => $this->purchase_type,
            'purchase_date' => $this->purchase_date,
            'from_company' => $this->from_company,
            'purchased_quantity' => $this->purchased_quantity,
            'price' => $this->price,
            'remarks' => $this->remarks,
        ]);

        session()->flash('success', 'Purchase updated successfully');
        if ($this->asModal) {
            $this->dispatch('purchase-saved');

            return;
        }

        return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'purchased']);
    }

    public function render()
    {
        return view('pulse::livewire.assets.asset-purchase-create-edit');
    }
}
