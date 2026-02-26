<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveCredit extends Model
{
    protected $fillable = [
        'user_id',
        'employment_status',
        'cycle_start_date',
        'cycle_end_date',
        'carry_forward_balance',
        'total_used',
        'provisional_leave_limit',
        'provisional_leave_taken',
        'provisional_extended_months',
        'notice_start_date',
        'paid_leaves',
        'bunch_time',
        'provisional_days',
        'joining_date',
    ];

    protected $casts = [
        'joining_date' => 'date',
        'cycle_start_date' => 'date',
        'provisional_leave_limit' => 'integer',
        'provisional_leave_taken' => 'integer',
        'carry_forward_balance' => 'integer',
        'worked_days' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
    public function isProvisional()
    {
        return $this->employment_status === 'provisional';
    }

    public function isAppointed()
    {
        return $this->employment_status === 'appointed';
    }

    public function isNotice()
    {
        return $this->employment_status === 'notice';
    }
    public function creditLogs()
    {
        return $this->hasMany(LeaveCreditLog::class, 'leave_credit_id');
    }
    public function logs()
    {
        return $this->hasMany(LeaveCreditLog::class);
    }
}
