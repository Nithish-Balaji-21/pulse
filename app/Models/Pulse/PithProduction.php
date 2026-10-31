<?php

namespace App\Models\Pulse;

use App\Models\Staff;
use App\Services\Pulse\ProductionEntryPolicy;
use App\Support\Pulse\ProductionEntryPolicyKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PithProduction extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $table = 'pulse_summary_pith_productions';

    protected $fillable = [
        'team_id',
        'device_id',
        'shift_date',
        'shift_id',
        'staff_id',
        'start_time',
        'end_time',
        'cft_produced',
        'load_hrs',
        'eb_units',
        'eb_price',
        'comments',
        'cft_expected',
        'shift_name',
    ];

    protected $casts = [
        'cft_produced' => 'float',
        'cft_expected' => 'float',
        'eb_units' => 'float',
        'eb_price' => 'float',
    ];

    public static function entryPolicyFor(int $teamId): ProductionEntryPolicy
    {
        return ProductionEntryPolicy::forTeam($teamId, ProductionEntryPolicyKeys::LOADS);
    }

    public function entryPolicy(): ProductionEntryPolicy
    {
        return self::entryPolicyFor((int) $this->team_id);
    }

    public static function minEditableDate(int $teamId): string
    {
        return self::entryPolicyFor($teamId)->minShiftDate();
    }

    public function canEditRecord(): bool
    {
        return $this->entryPolicy()->canEdit((string) $this->shift_date);
    }

    public function canDeleteRecord(): bool
    {
        return $this->entryPolicy()->canDelete((string) $this->shift_date);
    }

    public function isEditable(): bool
    {
        return $this->canEditRecord();
    }

    public $timestamps = true;

    protected static function booted()
    {
        // Add a "deleting" event listener to the model.
        static::deleting(function ($production) {
            // Before the PithProduction record is deleted, delete all associated PithInventory records.
            $production->pithLoads()->delete();
        });
    }

    // Defining the relationship with the User model
    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /**
     * Get the device that the shift belongs to.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Get the shift (from master) that the shift belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function pithLoads()
    {
        return $this->hasMany(PithInventory::class);
    }

    public function getTotalPithLoadsAttribute()
    {
        return $this->pithLoads()->sum('cft_offloaded');
    }

    public function getLoadsProducedAttribute()
    {
        return $this->attributes['cft_produced'] ?? null;
    }

    public function setLoadsProducedAttribute($value)
    {
        $this->attributes['cft_produced'] = $value;
    }

    public function getLoadsExpectedAttribute()
    {
        return $this->attributes['cft_expected'] ?? null;
    }

    public function setLoadsExpectedAttribute($value)
    {
        $this->attributes['cft_expected'] = $value;
    }
}
