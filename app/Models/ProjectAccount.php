<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAccount extends Model
{
    protected $table = 'project_accounts';

    protected $fillable = [
        'source_id',
        'account_name',
    ];
}
