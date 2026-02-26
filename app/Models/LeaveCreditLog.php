<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveCreditLog extends Model
{
    protected $table = 'leave_credit_logs';

    protected $fillable = [
        'leave_credit_id',
        'user_id',
        'year',
        'month',
        'worked_days',
        'monthly_paid_leave',
        'used_in_month',
        'converted_to_unpaid',
    ];

    public function leaveCredit()
    {
        return $this->belongsTo(LeaveCredit::class, 'leave_credit_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
