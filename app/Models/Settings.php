<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settings extends Model
{
    protected $table = 'pulse_settings';

    protected $fillable = [
        'team_id',
        'module',
        'key',
        'text',
        'value',
        'options',
        'value_remark',
        'updated_by',
    ];

    public $timestamps = true;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
