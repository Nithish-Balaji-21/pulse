<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamAddress extends Model
{
    use HasFactory;

    protected $table = 'team_address';

    protected $fillable = [
        'team_id',
        'type',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'pincode',
    ];

    public function getFormattedAddressAttribute(): string
    {
        return collect([
            trim($this->address_line_1 ?? ''),
            trim($this->address_line_2 ?? ''),
            trim($this->city ?? ''),
            trim($this->state ?? ''),
        ])->filter()->implode(', ');
    }
}
