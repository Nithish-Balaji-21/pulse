<?php

namespace App\Models\Pulse;

use App\Models\Staff;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $table = 'pulse_attendance';

    public $timestamps = true;

    // Mass-assignable attributes
    protected $fillable = [
        'team_id',
        'staff_id',
        'date',
        'duration',
        'salary',
        'activity_id',
        'task_description',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    // Relationship with the Team model
    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    // Relationship with the User model
    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
