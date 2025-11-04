<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\TagsActivity;
use App\Http\Resources\ClientResource;
use App\Models\User; // Assuming project manager is stored in users table

class ProjectResource extends JsonResource
{


public function toArray($request)
{
    $tagsIds = json_decode($this->tags_activitys, true);
    $project_type = ucfirst($this->project_type);

    $tags = is_array($tagsIds)
        ? TagsActivity::whereIn('id', $tagsIds)->get(['id', 'name'])
        : [];

    return [
        'id' => $this->id,
        'project_name' => $this->project_name,
        'project_type' => $project_type,
        'project_status' => $this->project_status,
        'client' => $this->client,
        'requirements' => $this->requirements,
        'budget' => $this->budget,
        'deadline' => $this->deadline,
        'tags_activities' => $tags,
        'status' => $this->status,  
        'technology' => $this->technology,
        'project_manager_ids' => $this->projectManager ? $this->projectManager->pluck('id') : [],
        'project_managers' => $this->projectManager ? $this->projectManager->map(function ($manager) {
            return [
                'id' => $manager->id,
                'name' => $manager->name
            ];
        }) : [],
        'created_at' => $this->created_at,
        'updated_at' => $this->updated_at,
    ];
}


}
