<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Team;
use App\Models\User;
use App\Http\Resources\TeamResource;
use App\Http\Helpers\ApiResponse;

class TeamController extends Controller
{
    // public function index()
    // {
    //     $teams = Team::with('users')->latest()->get();
    //     return ApiResponse::success('Teams fetched successfully', TeamResource::collection($teams));
    // }
    public function index()
{
    // 1. Fetch all teams
    $teams = Team::latest()->get();

    if ($teams->isEmpty()) {
        return ApiResponse::success('Teams fetched successfully', []);
    }

    // 2. Collect team IDs
    $teamIds = $teams->pluck('id')->values()->all();

    // 3. Fetch users whose JSON team_id contains ANY team id
    //    (works even if array has multiple IDs)
    $users = User::select('id','name','email','phone_num','role_id','team_id')
        ->where(function ($q) use ($teamIds) {
            foreach ($teamIds as $tid) {
                $q->orWhereJsonContains('team_id', $tid);
            }
        })
        ->get();

    // 4. Group users by team id
    $usersByTeam = [];

    foreach ($users as $user) {

        // Ensure JSON array, ignore nulls
        $teamArray = $user->team_id ?? [];

        // Clean values (remove nulls & cast to integer)
        $teamArray = array_values(
            array_filter(
                array_map(fn($val) => is_numeric($val) ? (int)$val : null, $teamArray)
            )
        );

        foreach ($teamArray as $tid) {
            $usersByTeam[$tid][] = $user;
        }
    }

    // 5. Bind users into each team model
    $teams = $teams->map(function ($team) use ($usersByTeam) {
        $team->setRelation(
            'users',
            collect($usersByTeam[$team->id] ?? [])
        );
        return $team;
    });

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
