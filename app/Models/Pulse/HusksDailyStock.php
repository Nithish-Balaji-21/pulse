<?php

namespace App\Models\Pulse;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HusksDailyStock extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pulse_husks_daily_stock';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'team_id',
        'date',
        'prod_date',
        'opening_stock',
        'husks_received',
        'cft',
        'closing_stock',
        'husks_correction',
        'correction_comment',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'date' => 'date',
        'prod_date' => 'date',
        'opening_stock' => 'double',
        'husks_received' => 'double',
        'cft' => 'double',
        'closing_stock' => 'double',
        'husks_correction' => 'double',
    ];

    /**
     * Get the team that owns the stock entry.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created/modified the entry (nullable).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
