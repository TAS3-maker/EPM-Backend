<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectRelationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'project_id' => $this->project_id,
            'communication_id' => $this->communication_id,
            'assignees_id' => $this->assignees,
            'source_id' => $this->source_id,
            'account_id' => $this->account_id,
            'sales_person_id' => $this->sales_person_id,
            'sales_person_data' => $this->sales_person_id(),
            'client' => $this->client ? $this->client->client_name : null,
            'project' => $this->project ? $this->project->project_name : null,
            'communications' => $this->communications(),
            'assignees' => $this->assignees(),
            'source' => $this->source ? $this->source->source_name : null,
            'account' => $this->account ? $this->account->account_name : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
