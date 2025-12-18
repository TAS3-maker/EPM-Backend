<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\CommunicationType;

class ProjectRelation extends Model
{
    protected $table = 'project_relations';

    protected $fillable = [
        'client_id',
        'project_id',
        'communication_id',
        'assignees',
        'source_id',
        'account_id',
        'sales_person_id',
    ];
    protected $casts = [
        'communication_id' => 'array',
        'assignees' => 'array',
    ];
    public function client()
    {
        return $this->belongsTo(\App\Models\ClientMaster::class, 'client_id');
    }

    public function project()
    {
        return $this->belongsTo(\App\Models\ProjectMaster::class, 'project_id');
    }

    public function sales_person_id()
    {
         return User::where(
            'id',
            $this->sales_person_id ?? ''
        )->get();
    }
    public function communications()
    {
        return CommunicationType::whereIn(
            'id',
            $this->communication_id ?? []
        )->get();
    }
    public function assignees()
    {
        return User::whereIn(
            'id',
            $this->assignees ?? []
        )->get();
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
