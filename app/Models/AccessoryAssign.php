<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessoryAssign extends Model
{
    protected $fillable = [
        'accessory_no','user_id', 'accessory_id', 'assigned_at','return_date','condition','notes','status'
    ];

    public function accessory()
    {
        return $this->belongsTo(Accessory::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
