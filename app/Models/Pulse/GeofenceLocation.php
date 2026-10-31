<?php

namespace App\Models\Pulse;

use App\Models\Team;
use App\Support\Pulse\GeofenceBoundary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeofenceLocation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const TYPE_FACTORY = 'factory';

    public const TYPE_MATERIAL_SHOP = 'material_shop';

    public const TYPE_SUPPLIER = 'supplier';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_FACTORY,
        self::TYPE_MATERIAL_SHOP,
        self::TYPE_SUPPLIER,
        self::TYPE_OTHER,
    ];

    protected $table = 'pulse_geofence_locations';

    protected $fillable = [
        'team_id',
        'name',
        'type',
        'boundary_type',
        'latitude',
        'longitude',
        'radius',
        'polygon_coordinates',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius' => 'integer',
            'polygon_coordinates' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GeofenceEvent::class, 'geofence_location_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'boundary_type' => $this->boundary_type ?: GeofenceBoundary::TYPE_CIRCLE,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radius' => $this->radius,
            'polygon_coordinates' => $this->boundary_type === GeofenceBoundary::TYPE_POLYGON
                ? ($this->polygon_coordinates ?? [])
                : null,
            'status' => $this->status,
            'updated_at' => $this->updated_at?->timezone('Asia/Kolkata')->toIso8601String(),
        ];
    }
}
