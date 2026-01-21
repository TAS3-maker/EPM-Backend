<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectSource extends Model
{
    protected $table = 'project_source';

    protected $fillable = [
        'source_name',
    ];
}
