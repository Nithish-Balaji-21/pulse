<?php

namespace App\Livewire\Pulse\PithBlocks;

use App\Models\Pulse\Device;
use App\Models\Pulse\PithBlocksProduction;
use App\Models\Pulse\Shift;
use App\Services\NumberFormatterService;
use App\Traits\ManagesProductionDayStock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class PithBlocksCreateAndEdit extends Component
{
    use ManagesProductionDayStock;

    public $productionId;

    public $teamId;

    public $date;

    public $startTime;

    public $endTime;

    public $staffId;

    public $blocksProduced;

    public $blocksExpected = 0;

    public $comments;

    public $staffs;

    public $showActuals = true;

    public function mount($id = null)
    {
        $currentTeam = app()->has('current.team') ? app('current.team') : Auth::user()->currentTeam;
        if (! $currentTeam || ! $currentTeam->has_pith_module) {
            return redirect("/{$currentTeam->id}/dashboard");
        }

        $this->showActuals = $currentTeam->showActuals();
        $this->teamId = $currentTeam->id;

        $policy = PithBlocksProduction::entryPolicyFor($this->teamId);

        if ($id) {
            $production = PithBlocksProduction::where('team_id', $this->teamId)->findOrFail($id);

            if (! $production->canEditRecord()) {
                session()->flash('error', 'This pith blocks record is locked or outside the allowed edit window.');

                return redirect()->route('pith-blocks.index', ['team' => $this->teamId]);
            }

            $this->productionId = $production->id;
            $this->date = Carbon::parse($production->shift_date)->toDateString();
            $this->startTime = $production->start_time;
            $this->endTime = $production->end_time;
            $this->staffId = $production->staff_id;
            $this->blocksProduced = $production->blocks_produced;
            $this->blocksExpected = $production->blocks_expected ?? 0;
            $this->comments = $production->comments;
            $this->loadStockForDate($this->date);
        } else {
            $this->date = $policy->maxCreateShiftDate();
            $this->applyTotalShiftWindow();
        }

        $this->staffs = $currentTeam->staffs()
            ->where(function ($query) {
                $query->where('is_active', true)->orWhere('id', $this->staffId);
            })
            ->orderBy('staff_name', 'asc')
            ->get();
    }

    public function updatedDate(): void
    {
        $this->applyTotalShiftWindow();
        if ($this->productionId) {
            $this->loadStockForDate($this->date);
        }
    }

    public function loadStockForDate(?string $date): void
    {
        if (! $date || ! strtotime($date) || ! $this->productionId) {
            return;
        }

        $production = PithBlocksProduction::where('team_id', $this->teamId)->find($this->productionId);
        if (! $production) {
            return;
        }

        $dateString = Carbon::parse($date)->toDateString();
        $previous = PithBlocksProduction::where('team_id', $this->teamId)
            ->whereDate('shift_date', '<', $dateString)
            ->orderByDesc('shift_date')
            ->first();

        $this->stockId = $production->id;
        $this->recordOpening = (int) ($previous?->blocks_in_stock ?? 0);
        $this->recordProduced = $production->producedForStock();
        $this->soldQuantity = $production->sold ?? 0;

        $correction = (int) ($production->correction ?? 0);
        $this->correctionType = $correction < 0 ? 'deduct' : 'add';
        $this->correctionAmount = $correction !== 0 ? abs($correction) : null;
        $this->correctionComment = $production->correction_comment;

        $lastSale = PithBlocksProduction::where('team_id', $this->teamId)
            ->whereDate('shift_date', '<', $dateString)
            ->where('sold', '>', 0)
            ->orderByDesc('shift_date')
            ->first();

        $this->lastEntryText = $lastSale
            ? 'Last sale: '.NumberFormatterService::formatToIndianFormat($lastSale->sold, false, 0)
                .' blocks on '.Carbon::parse($lastSale->shift_date)->format('d/m')
            : null;
    }

    protected function stockUnit(): string
    {
        return 'blocks';
    }

    protected function applyTotalShiftWindow(): void
    {
        if (! $this->date) {
            $this->startTime = '';
            $this->endTime = '';

            return;
        }

        $anchor = Shift::anchorForTeam((int) $this->teamId);
        $start = Carbon::parse($this->date)->setTimeFromTimeString($anchor);
        $end = $start->copy()->addDay();

        $this->startTime = $start->toDateTimeString();
        $this->endTime = $end->toDateTimeString();
    }

    public function save()
    {
        $capability = $this->productionId ? 'pith_blocks.edit' : 'pith_blocks.create';
        if (! Gate::allows($capability)) {
            session()->flash('error', 'You are not authorized to save pith blocks production records');

            return;
        }

        $policy = PithBlocksProduction::entryPolicyFor((int) $this->teamId);

        $rules = [
            'date' => $this->productionId
                ? 'required|date|after_or_equal:'.$policy->minShiftDate().'|before_or_equal:'.$policy->liveShiftDay()
                : 'required|date|after_or_equal:'.$policy->minShiftDate().'|before_or_equal:'.$policy->maxCreateShiftDate(),
            'staffId' => 'nullable|exists:staffs,id',
            'blocksProduced' => $this->showActuals ? 'required|integer' : 'nullable|integer',
            'comments' => 'nullable|string',
        ];

        if ($this->productionId) {
            $rules = array_merge($rules, $this->stockValidationRules());
        }

        $this->validate($rules);

        $this->date = Carbon::parse($this->date)->toDateString();
        $this->applyTotalShiftWindow();

        if ($this->productionId) {
            $existing = PithBlocksProduction::where('team_id', $this->teamId)->find($this->productionId);
            if (! $existing || ! $policy->canEdit($this->date)) {
                $this->addError('date', 'Date is outside the allowed edit window.');

                return;
            }
        } elseif (! $policy->canCreate($this->date)) {
            $this->addError('date', 'Date is outside the allowed create window.');

            return;
        }

        $exists = PithBlocksProduction::where('team_id', $this->teamId)
            ->whereDate('shift_date', $this->date)
            ->when($this->productionId, fn ($q) => $q->where('id', '!=', $this->productionId))
            ->exists();

        if ($exists) {
            $this->addError('duplicate', 'Pith Blocks production already exists for this date.');

            return;
        }

        $device = Device::where('team_id', $this->teamId)->where('module', 'pith')->first();

        if ($this->productionId) {
            $production = PithBlocksProduction::where('team_id', $this->teamId)->findOrFail($this->productionId);

            if (! $production->canEditRecord()) {
                session()->flash('error', 'This pith blocks record is locked or outside the edit window.');

                return redirect()->route('pith-blocks.index', ['team' => $this->teamId]);
            }

            $production->update([
                'shift_date' => $this->date,
                'staff_id' => $this->staffId ?: null,
                'blocks_produced' => $this->blocksProduced,
                'start_time' => $this->startTime,
                'end_time' => Carbon::parse($this->endTime)->subSecond(),
                'comments' => $this->comments,
                'sold' => (int) $this->soldQuantity,
                'correction' => $this->signedStockCorrection(),
                'correction_comment' => $this->correctionComment,
            ]);
        } else {
            PithBlocksProduction::create([
                'team_id' => $this->teamId,
                'shift_date' => $this->date,
                'device_id' => $device->device_id ?? null,
                'staff_id' => $this->staffId ?: null,
                'start_time' => $this->startTime,
                'end_time' => Carbon::parse($this->endTime)->subSecond(),
                'blocks_produced' => $this->blocksProduced,
                'comments' => $this->comments,
            ]);
        }

        session()->flash('message', 'Pith Blocks production saved successfully.');

        return redirect()->route('pith-blocks.index', ['team' => $this->teamId]);
    }

    public function render()
    {
        $policy = PithBlocksProduction::entryPolicyFor((int) $this->teamId);

        return view('pulse::livewire.pith-blocks.pith-blocks-create-and-edit', [
            'entryPolicy' => $policy,
            'minDate' => $policy->minShiftDate(),
            'maxDate' => $this->productionId ? $policy->liveShiftDay() : $policy->maxCreateShiftDate(),
        ]);
    }
}
