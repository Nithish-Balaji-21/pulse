<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PithLocation extends Model
{
    protected $table = 'pulse_pith_locations';

    protected $fillable = [
        'team_id',
        'group_id',
        'name',
        'is_active',
        'low_stock_alert_value',
    ];

    public $timestamps = true;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PithLocationGroup::class, 'group_id');
    }

    public function pithLoads(): HasMany
    {
        return $this->hasMany(PithInventory::class);
    }

    public function pithStockDepletions(): HasMany
    {
        return $this->hasMany(PithStockDepletion::class);
    }

    public function waterings(): HasMany
    {
        return $this->hasMany(PithWatering::class);
    }

    /**
     * Locations that still matter for reporting: the active ones, plus deactivated
     * ones that have not been emptied and so still hold live loads. Emptying a
     * location archives its loads, which is what drops it out of this set.
     */
    public function scopeActiveOrHoldingStock(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('pulse_pith_locations.is_active', true)
                ->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('pulse_pith_inventory')
                        ->whereColumn('pulse_pith_inventory.pith_location_id', 'pulse_pith_locations.id')
                        ->where('pulse_pith_inventory.is_archived', 0);
                });
        });
    }

    /**
     * Stock still sitting at this location: non-archived inward cft minus outward cft.
     * Emptying a location archives its loads, which brings this back to zero.
     */
    public function remainingStockCft(): float
    {
        $totals = $this->pithLoads()
            ->where('is_archived', 0)
            ->selectRaw('COALESCE(SUM(cft_offloaded), 0) AS inward, COALESCE(SUM(taken_cft_calculated), 0) AS outward')
            ->first();

        return round((float) $totals->inward - (float) $totals->outward, 2);
    }

    /**
     * Only locations that hold no stock may be deactivated.
     */
    public function hasRemainingStock(): bool
    {
        return $this->remainingStockCft() > 0;
    }
}
