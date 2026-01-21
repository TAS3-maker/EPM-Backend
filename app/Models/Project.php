<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\TagsActivity;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['sales_team_id', 'remaining_hours' , 'client_id', 'project_name', 'requirements', 'budget', 'used_budgets', 'deadline', 'total_hours', 'used_hours', 'project_manager_id', 'tags_activitys', 'assigned_by','technology', 'project_type' , 'project_status','status'];

    protected $appends = ['tags_activity_names']; 

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function salesTeam()
    {
        return $this->belongsTo(Team::class, 'sales_team_id');
    }

    public function projectManager()
    {
        return $this->belongsToMany(User::class, 'project_manager_project', 'project_id', 'project_manager_id')
                ->withPivot('assigned_by')
                ->withTimestamps();
    }

	public function projectClient()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function assignedEmployees()
    {
        return $this->belongsToMany(User::class, 'project_user', 'project_id', 'user_id');
    }
    public function teamLead()
    {
        return $this->belongsTo(User::class, 'tl_id');
    }

	public function assignedUsers()
    {
        return $this->belongsToMany(User::class, 'project_user');
    }

	// public function performaSheets()
    // {
    //     return $this->hasMany(PerformaSheet::class, 'project_id', 'id');
    // }

    public function tasks()
    {
        return $this->hasMany(\App\Models\Task::class, 'project_id', 'id');
    }
    public function getTagsActivityNamesAttribute()
    {
        $ids = json_decode($this->tags_activitys, true);
        if (empty($ids)) {
            return [];
        }

        return TagsActivity::whereIn('id', $ids)->get(['id', 'name']);
    }

}
