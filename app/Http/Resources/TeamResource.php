<?php

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
            // 'department_id'=> $this->department_id ? Department::where('id',$this->department_id)->get(['id','name'])  : null,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name
            ] : null,
            'users' => $this->users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone_num,
                    'role' => $user->role ? $user->role->name : null,
                    'team_id' => $user->team_id, // array
                ];
            })
        ];
    }
}
