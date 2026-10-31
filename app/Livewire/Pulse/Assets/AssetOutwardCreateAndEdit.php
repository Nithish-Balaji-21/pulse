<?php

namespace App\Livewire\Pulse\Assets;

use App\Models\Pulse\Asset;
use App\Models\Pulse\AssetOutward;
use App\Models\Pulse\AssetPurchase;
use App\Models\Pulse\AssetReturn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class AssetOutwardCreateAndEdit extends Component
{
    public $teamId;

    public ?int $assetId = null;

    public ?int $outwardId = null;

    public ?Asset $asset = null;

    public bool $editing = false;

    public bool $asModal = false;

    public string $outward_type = 'Maintenance';

    public string $outward_date = '';

    public string $to_company = '';

    public int $outward_quantity = 0;

    public ?string $approved_by = null;

    public ?string $remarks = null;

    public int $maxAllowed = 0;

    public int $alreadyReturnedForThisOutward = 0;

    public function mount($assetId = null, $outwardId = null, $asModal = false)
    {
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->assetId = $assetId ? (int) $assetId : null;
        $this->outwardId = $outwardId ? (int) $outwardId : null;
        $this->asModal = (bool) $asModal;

        if ($this->outwardId) {
            $this->editing = true;
            $outward = AssetOutward::findOrFail($this->outwardId);
            $this->assetId = (int) $outward->asset_id;
            $this->asset = Asset::findOrFail($this->assetId);

            $this->outward_type = (string) $outward->outward_type;
            $this->outward_date = (string) $outward->outward_date;
            $this->to_company = (string) $outward->to_company;
            $this->outward_quantity = (int) $outward->outward_quantity;
            $this->approved_by = $outward->approved_by;
            $this->remarks = $outward->remarks;

            $this->alreadyReturnedForThisOutward = (int) AssetReturn::where('outward_id', $this->outwardId)->sum('returned_quantity');

            $purchasedTotal = (int) AssetPurchase::where('asset_id', $this->assetId)->sum('purchased_quantity');
            $returnedTotal = (int) AssetReturn::whereIn('outward_id', AssetOutward::where('asset_id', $this->assetId)->select('outward_id'))
                ->sum('returned_quantity');
            $otherOutwards = (int) AssetOutward::where('asset_id', $this->assetId)
                ->where('outward_id', '!=', $this->outwardId)
                ->sum('outward_quantity');

            $this->maxAllowed = max(0, ($purchasedTotal + $returnedTotal) - $otherOutwards);
        } else {
            $this->editing = false;
            $this->asset = Asset::findOrFail((int) $this->assetId);

            $purchasedTotal = (int) AssetPurchase::where('asset_id', $this->assetId)->sum('purchased_quantity');
            $outwardTotal = (int) AssetOutward::where('asset_id', $this->assetId)->sum('outward_quantity');
            $returnedTotal = (int) AssetReturn::whereIn('outward_id', AssetOutward::where('asset_id', $this->assetId)->select('outward_id'))
                ->sum('returned_quantity');
            $this->maxAllowed = max(0, ($purchasedTotal + $returnedTotal) - $outwardTotal);
        }
    }

    protected function rules()
    {
        return [
            'outward_type' => ['required', Rule::in(['Maintenance', 'Loaned', 'Scrap', 'Sold', 'Transferred'])],
            'outward_date' => ['required', 'date', 'before_or_equal:today'],
            'to_company' => ['required', 'string', 'max:50'],
            'outward_quantity' => ['required', 'integer', 'min:1', 'lte:'.$this->maxAllowed],
            'approved_by' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function save()
    {
        $this->validate();

        AssetOutward::create([
            'asset_id' => (int) $this->assetId,
            'outward_type' => $this->outward_type,
            'outward_date' => $this->outward_date,
            'to_company' => $this->to_company,
            'outward_quantity' => $this->outward_quantity,
            'approved_by' => $this->approved_by,
            'remarks' => $this->remarks,
        ]);
        if ($this->asModal) {
            $this->dispatch('outward-saved');

            return;
        }

        return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'outward']);
    }

    public function update()
    {
        $this->validate();

        $outward = AssetOutward::find($this->outwardId);
        if (! $outward) {
            session()->flash('error', 'Outward record not found');

            return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'outward']);
        }

        if ($outward->outward_type !== 'Scrap' && $this->outward_type === 'Scrap') {
            $returnsCount = $outward->returns()->count();
            if ($returnsCount > 0) {
                $outward->returns()->delete();
                $this->alreadyReturnedForThisOutward = 0;
                session()->flash('success', 'Existing return records were deleted because this outward is now Scrap.');
            }
        }
        if ($this->outward_quantity < $this->alreadyReturnedForThisOutward) {
            session()->flash('error', 'Cannot Update: Outward quantity is less than returned record quantity');

            return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'outward']);
        }

        $outward->update([
            'outward_type' => $this->outward_type,
            'outward_date' => $this->outward_date,
            'to_company' => $this->to_company,
            'outward_quantity' => $this->outward_quantity,
            'approved_by' => $this->approved_by,
            'remarks' => $this->remarks,
        ]);

        session()->flash('success', 'Outward updated successfully');
        if ($this->asModal) {
            $this->dispatch('outward-saved');

            return;
        }

        return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'outward']);
    }

    public function render()
    {
        return view('pulse::livewire.assets.asset-outward-create-edit');
    }
}
