<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\CommunicationType;
use App\Models\ProjectAccount;

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
        'tracking_id',
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
        $assigneeIds = $this->assignees ?? [];

        if (empty($assigneeIds)) {
            return collect(); 
        }

        $users = User::whereIn('id', $assigneeIds)
            ->where('is_active', 1)
            ->get();

        $users->transform(function ($user) {
            $user->role_ids = is_array($user->role_id)
                ? array_map('intval', $user->role_id)
                : (is_string($user->role_id)
                    ? array_map('intval', explode(',', $user->role_id))
                    : [$user->role_id]);

            $user->role_names = Role::whereIn('id', $user->role_ids)
                ->pluck('name')
                ->toArray();

            return $user;
        });

        return $users;
    }


    public function source()
    {
        return $this->belongsTo(\App\Models\ProjectSource::class, 'source_id');
    }

    public function account()
    {
        return $this->belongsTo(\App\Models\ProjectAccount::class, 'account_id');
    }
    public function trackingID()
    {
        return ProjectAccount::where(
            'id',
            $this->tracking_id ?? ''
        )->get();
    }
}
