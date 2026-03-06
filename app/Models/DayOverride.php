<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DayOverride extends Model
{
    protected $fillable = [
        'date',
        'is_working',
        'reason'
    ];
}
