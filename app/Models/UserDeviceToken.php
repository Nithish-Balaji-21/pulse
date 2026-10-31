<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDeviceToken extends Model
{
    protected $table = 'user_device_tokens';

    protected $fillable = [
        'user_id',
        'device_id',
        'device_platform',
        'fcm_token',
    ];

    public $timestamps = true;
}
