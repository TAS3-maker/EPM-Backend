<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationPerforma extends Model
{
    protected $table = 'application_performa';

    protected $fillable = [
        'user_id',
        'performa_sheet',
        'apply_date',
        'status',
        'approval_date'
    ];

    protected $casts = [
        'approval_date' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
