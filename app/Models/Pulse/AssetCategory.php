<?php

namespace App\Models\Pulse;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    use HasFactory;

    protected $table = 'pulse_asset_categories';

    protected $fillable = [
        'team_id',
        'name',
        'is_active',
    ];

    public $timestamps = true;

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
