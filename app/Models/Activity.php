<?php

namespace App\Models;

use App\Models\Pulse\Attendance;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    protected $table = 'activities';

    // Specify the fields that are mass assignable
    protected $fillable = [
        'team_id',
        'activity_name',
    ];

    public $timestamps = true;

    public function attendance()
    {
        return $this->hasMany(Attendance::class);
    }
}
