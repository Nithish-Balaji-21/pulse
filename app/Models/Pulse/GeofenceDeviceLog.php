<?php

namespace App\Models\Pulse;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceDeviceLog extends Model
{
    protected $table = 'pulse_geofence_mobile_logs';

    protected $fillable = [
        'user_id',
        'team_id',
        'step',
        'category',
        'reason',
        'geofence_id',
        'event_type',
        'duration_ms',
        'battery_percent',
        'battery_optimization_ignored',
        'gps_enabled',
        'location_permission',
        'background_location_permission',
        'device_model',
        'staff_label',
        'trackable',
        'code_slow',
        'device_slow',
        'os_slow',
        'extra',
        'recorded_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'battery_optimization_ignored' => 'boolean',
            'gps_enabled' => 'boolean',
            'trackable' => 'boolean',
            'code_slow' => 'boolean',
            'device_slow' => 'boolean',
            'os_slow' => 'boolean',
            'extra' => 'array',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
            'duration_ms' => 'integer',
            'battery_percent' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Categories that represent a problem / skip / fail for owner diagnostics. */
    public const PROBLEM_CATEGORIES = [
        'EVENT_SKIPPED',
        'HEALTH_FAIL',
        'GATE_BLOCKED',
        'EVENT_QUEUED',
        'REGISTER_FAIL',
        'BOOT_FAIL',
        'CAPTURE_FAIL',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'staff_label' => $this->staff_label ?? $this->user?->name,
            'step' => $this->step,
            'category' => $this->category,
            'reason' => $this->reason,
            'geofence_id' => $this->geofence_id,
            'event_type' => $this->event_type,
            'duration_ms' => $this->duration_ms,
            'battery_percent' => $this->battery_percent,
            'battery_optimization_ignored' => $this->battery_optimization_ignored,
            'gps_enabled' => $this->gps_enabled,
            'location_permission' => $this->location_permission,
            'background_location_permission' => $this->background_location_permission,
            'trackable' => $this->trackable,
            'code_slow' => $this->code_slow,
            'device_slow' => $this->device_slow,
            'os_slow' => $this->os_slow,
            'device_model' => $this->device_model,
            'extra' => $this->extra,
            'recorded_at' => $this->recorded_at?->timezone('Asia/Kolkata')->toIso8601String(),
        ];
    }
}
