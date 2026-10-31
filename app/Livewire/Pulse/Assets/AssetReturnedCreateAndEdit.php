<?php

namespace App\Livewire\Pulse\Assets;

use App\Models\Pulse\Asset;
use App\Models\Pulse\AssetOutward;
use App\Models\Pulse\AssetReturn;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AssetReturnedCreateAndEdit extends Component
{
    public $teamId;

    public ?int $assetId = null;

    public ?int $outwardId = null;

    public ?int $returnId = null;

    public ?Asset $asset = null;

    public bool $editing = false;

    public bool $asModal = false;

    public string $returned_date = '';

    public int $returned_quantity = 0;

    public ?string $remarks = null;

    public int $outward_quantity = 0;

    public int $maxAllowed = 0; // remaining limit

    public int $returnedSoFar = 0; // other returns for this outward

    public function mount($outwardId = null, $returnId = null, $asModal = false)
    {
        $this->teamId = app()->has('current.team') ? app('current.team')->id : Auth::user()->currentTeam->id;
        $this->outwardId = $outwardId ? (int) $outwardId : null;
        $this->returnId = $returnId ? (int) $returnId : null;
        $this->asModal = (bool) $asModal;

        if ($this->returnId) {
            $this->editing = true;
            $return = AssetReturn::findOrFail($this->returnId);
            $this->outwardId = (int) $return->outward_id;
            $outward = AssetOutward::findOrFail($this->outwardId);
            $this->assetId = (int) $outward->asset_id;
            $this->asset = Asset::findOrFail($this->assetId);

            $this->returned_date = (string) $return->returned_date;
            $this->returned_quantity = (int) $return->returned_quantity;
            $this->remarks = $return->remarks;

            $this->outward_quantity = (int) $outward->outward_quantity;

            $otherReturns = (int) AssetReturn::where('outward_id', $this->outwardId)
                ->where('return_id', '!=', $this->returnId)
                ->sum('returned_quantity');

            $this->returnedSoFar = $otherReturns;
            $this->maxAllowed = max(0, $this->outward_quantity - $otherReturns);
        } else {
            $this->editing = false;
            $outward = AssetOutward::findOrFail((int) $this->outwardId);
            $this->assetId = (int) $outward->asset_id;
            $this->asset = Asset::findOrFail($this->assetId);
            $this->outward_quantity = (int) $outward->outward_quantity;

            $returnedSoFar = (int) AssetReturn::where('outward_id', $this->outwardId)->sum('returned_quantity');
            $this->returnedSoFar = $returnedSoFar;
            $this->maxAllowed = max(0, $this->outward_quantity - $returnedSoFar);
        }
    }

    protected function rules()
    {
        return [
            'returned_date' => ['required', 'date'],
            'returned_quantity' => ['required', 'integer', 'min:1', 'lte:'.$this->maxAllowed],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function save()
    {
        $this->validate();

        AssetReturn::create([
            'outward_id' => (int) $this->outwardId,
            'returned_date' => $this->returned_date,
            'returned_quantity' => $this->returned_quantity,
            'remarks' => $this->remarks,
        ]);
        if ($this->asModal) {
            $this->dispatch('return-saved');

            return;
        }

        return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'returned']);
    }

    public function update()
    {
        $this->validate();
        $return = AssetReturn::find($this->returnId);
        if (! $return) {
            session()->flash('error', 'Return record not found');

            return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'returned']);
        }

        $return->update([
            'returned_date' => $this->returned_date,
            'returned_quantity' => $this->returned_quantity,
            'remarks' => $this->remarks,
        ]);

        session()->flash('success', 'Return updated successfully');
        if ($this->asModal) {
            $this->dispatch('return-saved');

            return;
        }

        return redirect()->route('pulse_assets.view', ['team' => $this->teamId, 'id' => $this->assetId, 'tab' => 'returned']);
    }

    public function render()
    {
        return view('pulse::livewire.assets.asset-returned-create-edit');
    }
}
