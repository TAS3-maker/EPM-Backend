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
            'source' => $this->source ? $this->source->source_name : null,
            'account_id' => $this->account_id,
            'account' => $this->account ? $this->account->account_name : null,
            'tracking_id' => $this->tracking_id,
            'tracking_account' => $this->trackingID(),
            'tracking_source_id' => $this->tracking_source_id,
            'tracking_source_name' => $this->tracking_source ? $this->tracking_source->source_name : null,
            'sales_person_id' => $this->sales_person_id,
            'project_estimation_by' => $this->project_estimation_by,
            'project_call_by' => $this->project_call_by,
            'sales_person_data' => $this->sales_person_id(),
            'project_estimation_by_data' => $this->project_estimation_by(),
            'project_call_by_data' => $this->project_call_by(),
            'client_name' => $this->client ? $this->client->client_name : null,
            'client_email' => $this->client ? $this->client->client_email : null,
            'client_number' => $this->client ? $this->client->client_number : null,
            'project' => $this->project ? $this->project->project_name : null,
            'communications' => $this->communications(),
            'assignees' => $this->assignees(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
