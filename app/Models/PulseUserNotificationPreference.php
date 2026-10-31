<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PulseUserNotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'team_id',
        'notification_type',
        'is_email_enabled',
        'is_push_enabled',
    ];

    protected $casts = [
        'is_email_enabled' => 'boolean',
        'is_push_enabled' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
