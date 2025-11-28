<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectRelation extends Model
{
    protected $table = 'project_relations';

    protected $fillable = [
        'client_id',
        'project_id',
        'communication_id',
        'source_id',
        'account_id',
    ];

    public function client()
    {
        return $this->belongsTo(\App\Models\ClientMaster::class, 'client_id');
    }

    public function project()
    {
        return $this->belongsTo(\App\Models\ProjectMaster::class, 'project_id');
    }

    public function communication()
    {
        return $this->belongsTo(\App\Models\CommunicationType::class, 'communication_id');
    }

    public function source()
    {
        return $this->belongsTo(\App\Models\ProjectSource::class, 'source_id');
    }

    public function account()
    {
        return $this->belongsTo(\App\Models\ProjectAccount::class, 'account_id');
    }
}
