<?php

namespace App\Models;

use App\Models\Pulse\Attendance;
use App\Models\Pulse\MiniBaleProduction;
use App\Models\Pulse\PithProduction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    use HasFactory;

    protected $table = 'staffs';

    protected $fillable = [
        'staff_name',
        'team_id',
        'is_active',
        'salary_per_hr',
        'created_on',
        'created_by',
        'updated_on',
        'updated_by',
    ];

    public $timestamps = true;

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function miniBaleProductions()
    {
        return $this->hasMany(MiniBaleProduction::class, 'staff_id');
    }

    public function pithProductions()
    {
        return $this->hasMany(PithProduction::class, 'staff_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'staff_id');
    }
}
