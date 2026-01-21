<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'hours' => $this->hours,
            'deadline' => $this->deadline,
            'project' => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->project_name
            ] : null,
            'project_manager' => $this->projectManager ? [
                'id' => $this->projectManager->id,
                'name' => $this->projectManager->name
            ] : null,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s')
        ];
    }
}