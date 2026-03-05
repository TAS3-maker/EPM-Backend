<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveCredit extends Model
{
    protected $fillable = [
        'user_id',
        'month',
        'year',
        'employment_status',
        'cycle_start_date',
        'carry_forward_balance',
        'provisional_leave_limit',
        'notice_start_date',
        'notice_period_days',
        'paid_leaves',
        'bunch_time',
        'bunch_payble_balance',
        'provisional_days',
        'joining_date',
    ];

    protected $casts = [
        'joining_date' => 'date',
        'cycle_start_date' => 'date',
        'provisional_leave_limit' => 'integer',
        'provisional_extended_months' => 'integer',
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
}
