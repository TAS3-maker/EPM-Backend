<?php

namespace App\Http\Resources;

use App\Models\Team as ModelTeam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $teamNames = [];
        if (is_array($this->team_id) && count($this->team_id) > 0) {
            $teams = ModelTeam::whereIn('id', $this->team_id)->get();
            $teamNames = $teams->pluck('name')->toArray();
        }
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_num' => $this->phone_num,
            'emergency_phone_num' => $this->emergency_phone_num,
            'tl_id' => $this->tl_id,
            'reporting_manager_id' => $this->reporting_manager_id,
            'role_id' => $this->role_ids,
            'roles' => $this->roles->pluck('name'),
            'address' => $this->address,
            'team_id' => $this->team_id,
            'teams' => $teamNames,
            'profile_pic' => $this->profile_pic ? asset('storage/profile_pics/' . $this->profile_pic) : null,
            'is_active' => $this->is_active ? 1 : 0,
            'inactive_date' => $this->inactive_date ? $this->inactive_date->format('Y-m-d') : null,
        ];
    }
}

