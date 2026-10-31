<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cache extends Model
{
    protected $table = 'cache'; // If your table name is 'cache'

    public $timestamps = false; // If your table does not have `created_at` and `updated_at` columns

    // Define fillable attributes if needed
    protected $fillable = ['key', 'value', 'expiration'];
}
