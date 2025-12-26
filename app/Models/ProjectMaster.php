<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\TagsActivity;

class ProjectMaster extends Model
{
    protected $table = 'projects_master';

    protected $fillable = [
        'project_name',
        'project_tracking',
        'project_status',
        'project_description',
        'project_budget',
        'project_hours',
        'project_tag_activity',
        'project_used_hours',
        'project_used_budget',
    ];
    // ProjectMaster.php
    public function tagActivity()
    {
        return TagsActivity::where(
            'id',
            $this->project_tag_activity ?? ''
        )->get();
    }
    public function tagActivityRelated()
    {
        return $this->belongsTo(
            TagsActivity::class,
            'project_tag_activity',
            'id'
        );
    }

}
