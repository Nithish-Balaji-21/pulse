<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PulseTeamRole extends Model
{
    protected $table = 'pulse_team_roles';

    /**
     * `key` is intentionally NOT fillable — it is the immutable role slug used in
     * permission checks, routes, and team_user.role. Set only via forceFill on create.
     */
    protected $fillable = [
        'team_id',
        'name',
        'description',
        'sort_order',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
