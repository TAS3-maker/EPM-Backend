<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventHoliday extends Model
{
    use HasFactory;

    protected $table = 'event_holidays';
    protected $fillable = [
        'start_date',
        'end_date',
        'type',
        'description',
        'start_time',
        'end_time',
        'halfday_period',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
    ];
}
