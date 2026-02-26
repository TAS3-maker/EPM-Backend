<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    use HasFactory;

    protected $table = 'leavespolicy';

    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'leave_type',
        'employment_period',
        'reason',
        'status',
        'hours',
        'total_hours',
        'deducted_days',
        'sandwich_extra_days',
        'halfday_period',
        'is_wfh'
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
