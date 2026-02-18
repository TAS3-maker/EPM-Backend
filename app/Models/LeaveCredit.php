<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveCredit extends Model
{
    protected $fillable = [
        'user_id',
        'paid_leaves',
        'bunch_time',
        'provisional_days',
        'joining_date',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}