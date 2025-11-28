<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationType extends Model
{
    protected $table = 'communication_type';

    protected $fillable = [
        'medium',
    ];
}
