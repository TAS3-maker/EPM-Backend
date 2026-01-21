<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => [
                'id' => $this->source_id,
                'name' => optional($this->source)->source_name,
            ],
            'account_name' => $this->account_name,
            'projects' => $this->projects->map(function ($project) {
                return [
                    'id' => $project->id,
                    'project_name' => $project->project_name,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
