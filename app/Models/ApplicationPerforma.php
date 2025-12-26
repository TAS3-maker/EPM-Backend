<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationPerforma extends Model
{
    protected $table = 'application_performa';

    protected $fillable = [
        'user_id',
        'data',
        'status',
        'apply_date',
        'approval_date'
    ];

    protected $casts = [
        'data' => 'array',
        'approval_date' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
