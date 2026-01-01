<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\UserResource;

class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'dashboard' => $this->dashboard,
            'permission' => $this->permission,
            'employee_management' => $this->employee_management,
            'roles' => $this->roles,
            'department' => $this->department,
            'team' => $this->team,
            'clients' => $this->clients,
            'projects' => $this->projects,
            'assigned_projects_inside_projects_assigned' => $this->assigned_projects_inside_projects_assigned,
            'unassigned_projects_inside_projects_assigned' => $this->unassigned_projects_inside_projects_assigned,
            'performance_sheets' => $this->performance_sheets,
            'pending_sheets_inside_performance_sheets' => $this->pending_sheets_inside_performance_sheets,
            'manage_sheets_inside_performance_sheets' => $this->manage_sheets_inside_performance_sheets,
            'unfilled_sheets_inside_performance_sheets' => $this->unfilled_sheets_inside_performance_sheets,
            'manage_leaves' => $this->manage_leaves,
            'activity_tags' => $this->activity_tags,
            'leaves' => $this->leaves,
            'teams' => $this->teams,
            'leave_management' => $this->leave_management,
            'project_management' => $this->project_management,
            'assigned_projects_inside_project_management' => $this->assigned_projects_inside_project_management,
            'unassigned_projects_inside_project_management' => $this->unassigned_projects_inside_project_management,
            'performance_sheet' => $this->performance_sheet,
            'performance_history' => $this->performance_history,
            'projects_assigned' => $this->projects_assigned,
            'project_master' => $this->project_master,
            'client_master' => $this->client_master,
            'project_source' => $this->project_source,
            'communication_type' => $this->communication_type,
            'account_master' => $this->account_master,
            'notes_management' => $this->notes_management,
            'team_reporting'=> $this->team_reporting,
            'leave_reporting'=> $this->leave_reporting,
            'previous_sheets'=> $this->previous_sheets,
            'offline_hours'=> $this->offline_hours,
        ];
    }
}
