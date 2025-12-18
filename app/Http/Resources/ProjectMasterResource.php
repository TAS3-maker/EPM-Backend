<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_name' => $this->project_name,
            'project_tracking' => $this->project_tracking,
            'project_status' => $this->project_status,
            'project_description' => $this->project_description,
            'project_budget' => $this->project_budget,
            'project_hours' => $this->project_hours,
            'project_tag_activity' => $this->project_tag_activity,
            'project_tag_activity_data' => $this->tagActivity(),
            'project_used_hours' => $this->project_used_hours,
            'project_used_budget' => $this->project_used_budget,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
