<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An audit row written during an impersonation session.
 *
 * Writes to this table deliberately bypass the read-only guard registered in
 * AppServiceProvider — otherwise read-only mode could not record itself.
 */
class ImpersonationLog extends Model
{
    protected $table = 'impersonation_logs';

    protected $fillable = [
        'session_uuid',
        'impersonator_id',
        'impersonated_id',
        'team_id',
        'action',
        'subject_type',
        'subject_id',
        'changes',
        'url',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function impersonated(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
