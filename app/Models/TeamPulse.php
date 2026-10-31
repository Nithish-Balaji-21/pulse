<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamPulse extends Model
{
    use HasFactory;

    protected $table = 'team_pulse';

    protected $fillable = [
        'team_id',
        'factory_address_id',
        'coordinates',
        'modules',
        'is_active',
        'license_valid_till',
        'installed_date',
    ];

    protected $casts = [
        'modules' => 'array',
        'is_active' => 'boolean',
        'license_valid_till' => 'date',
        'installed_date' => 'date',
    ];
}
