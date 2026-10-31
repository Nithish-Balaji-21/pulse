<?php

namespace App\Traits;

use App\Services\NumberFormatterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Sold + stock correction for a production day.
 *
 * Fibre bales and pith blocks override load/persist on the production summary row.
 */
trait ManagesProductionDayStock
{
    public $soldQuantity;

    public $correctionType = 'add';

    public $correctionAmount;

    public $correctionComment;

    public $recordOpening = 0;

    public $recordProduced = 0;

    public $lastEntryText;

    public $stockId;

    /**
     * @return class-string|null
     */
    protected function stockModel(): ?string
    {
        return null;
    }

    public function loadStockForDate(?string $date): void
    {
        if (! $date || ! strtotime($date)) {
            return;
        }

        $model = $this->stockModel();
        if ($model === null) {
            return;
        }

        $record = $model::forDate((int) $this->teamId, Carbon::parse($date)->toDateString());

        $this->stockId = $record->id;
        $this->recordOpening = (int) $record->opening_stock;
        $this->recordProduced = (int) $record->produced;
        $this->soldQuantity = $record->sold ?? 0;

        $correction = (int) $record->correction;
        $this->correctionType = $correction < 0 ? 'deduct' : 'add';
        $this->correctionAmount = $correction !== 0 ? abs($correction) : null;
        $this->correctionComment = $record->correction_comment;

        $lastSale = $model::where('team_id', $this->teamId)
            ->whereDate('date', '<', $date)
            ->where('sold', '>', 0)
            ->orderByDesc('date')
            ->first();

        $this->lastEntryText = $lastSale
            ? 'Last sale: '.NumberFormatterService::formatToIndianFormat($lastSale->sold, false, 0)
                .' '.$model::unit().' on '.$lastSale->date->format('d/m')
            : null;
    }

    protected function stockValidationRules(): array
    {
        return [
            'soldQuantity' => 'required|integer|min:0',
            'correctionType' => 'required|in:add,deduct',
            'correctionAmount' => 'nullable|integer|min:0',
            'correctionComment' => 'nullable|string|max:150',
        ];
    }

    public function persistStockForDate(string $date): void
    {
        $model = $this->stockModel();
        if ($model === null) {
            return;
        }

        $record = $model::where('team_id', $this->teamId)->where('id', $this->stockId)->first();

        if (! $record) {
            $record = $model::forDate((int) $this->teamId, Carbon::parse($date)->toDateString());
            $this->stockId = $record->id;
        }

        $signedCorrection = ($this->correctionAmount ?? 0) > 0
            ? ($this->correctionType === 'deduct' ? -1 : 1) * (int) $this->correctionAmount
            : 0;

        $record->update([
            'user_id' => Auth::id(),
            'sold' => (int) $this->soldQuantity,
            'correction' => $signedCorrection,
            'correction_comment' => $this->correctionComment,
        ]);

        $model::reconcile((int) $this->teamId, Carbon::parse($date)->toDateString());
    }

    protected function stockUnit(): string
    {
        $model = $this->stockModel();

        return $model ? $model::unit() : 'units';
    }

    protected function signedStockCorrection(): int
    {
        return ($this->correctionAmount ?? 0) > 0
            ? ($this->correctionType === 'deduct' ? -1 : 1) * (int) $this->correctionAmount
            : 0;
    }
}
