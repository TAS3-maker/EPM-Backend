<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyWorkingDay extends Model
{
    protected $fillable = [
        'day_of_week',
        'is_working'
    ];
}
