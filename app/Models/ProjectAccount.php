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
    public function projectRelations()
    {
        return $this->hasMany(ProjectRelation::class, 'account_id');
    }
    public function projects()
    {
        return $this->hasManyThrough(
            ProjectMaster::class,
            ProjectRelation::class,
            'account_id',
            'id', 
            'id',
            'project_id' 
        );
    }
    public function source()
    {
        return $this->belongsTo(ProjectSource::class, 'source_id');
    }

}
