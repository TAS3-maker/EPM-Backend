<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientMaster extends Model
{
    protected $table = 'clients_master';

    protected $fillable = [
        'client_name',
        'client_email',
        'client_number',
    ];
}
