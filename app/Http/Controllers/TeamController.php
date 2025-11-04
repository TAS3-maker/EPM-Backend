<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Team;
use App\Http\Resources\TeamResource;
use App\Http\Helpers\ApiResponse;

class TeamController extends Controller
{
    public function index()
    {
        $teams = Team::with('users')->latest()->get();
        return ApiResponse::success('Teams fetched successfully', TeamResource::collection($teams));
    }

    public function show($id)
    {
        $team = Team::with('users')->find($id);

        if (!$team) {
            return ApiResponse::error('Team not found', [], 404);
        }

        return ApiResponse::success('Team details fetched successfully', new TeamResource($team));
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:teams',
                'department_id' => 'required|exists:departments,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error('Validation Error', $e->errors(), 422);
        }

        $team = Team::create([
            'name' => $request->name,
            'department_id' => $request->department_id,
        ]);

        return ApiResponse::success('Team created successfully', $team, 200);
    }

    public function update(Request $request, $id)
    {
        $team = Team::find($id);

        if (!$team) {
            return ApiResponse::error('Team not found', [], 404);
        }

        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:teams,name,' . $id,
                'department_id' => 'required|exists:departments,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error('Validation Error', $e->errors(), 422);
        }

        $team->update([
            'name' => $request->name,
            'department_id' => $request->department_id,
        ]);

        return ApiResponse::success('Team updated successfully', $team);
    }

    public function destroy($id)
    {
        $team = Team::find($id);

        if (!$team) {
            return ApiResponse::error('Team not found', [], 404);
        }

        $team->delete();
        return ApiResponse::success('Team deleted successfully');
    }
}
