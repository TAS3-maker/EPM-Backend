<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectMaster extends Model
{
    protected $table = 'projects_master';

    protected $fillable = [
        'project_name',
    ];
}
