<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\ProjectAssignedMail;
use Illuminate\Support\Facades\DB;
use App\Models\Project;
use App\Models\Client;
use App\Models\User;
use App\Models\ProjectUser;
use App\Models\PerformaSheet;
use App\Models\TagsActivity;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\ProjectResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use LDAP\Result;
use App\Mail\ProjectAssignedToTLMail;


class ProjectController extends Controller
{
    public function index()
    {
        return ApiResponse::success('Projects fetched successfully', ProjectResource::collection(Project::with('client', 'salesTeam')->orderBy('id','DESC')->get()));
    }
    public function store(Request $request)
{
    $messages = [
        'project_name.unique' => 'The project name has already been taken.',
    ];

    $validator = Validator::make($request->all(), [
        'sales_team_id' => 'required',
        'client_id' => 'required|exists:clients,id',
        'project_name' => 'required|string|max:255|unique:projects,project_name',
        'requirements' => 'nullable|string',
        'project_type' => 'nullable|string|in:fixed,hourly',
        'budget' => 'nullable|numeric',
        'deadline' => 'nullable|date',
        'total_hours' => 'nullable|string',
        'tags_activitys' => 'nullable|array',
        'technology' => 'nullable|array',
        'project_status' => 'required|string|in:online,offline',
        'status' => 'nullable|string|in:Active,Inactive', 
    ], $messages);

    if ($validator->fails()) {
        return ApiResponse::error('Validation failed', $validator->errors(), 422);
    }

    $validatedData = $validator->validated();

    if ($request->has('tags_activitys')) {
        $validatedData['tags_activitys'] = json_encode($request->tags_activitys);
    }

    if ($request->has('technology')) {
        $validatedData['technology'] = json_encode($request->technology);
    }

    $project = Project::create($validatedData);
    
    return ApiResponse::success('Project created successfully', $project, 201);
}



public function assignProjectToManager(Request $request)
{
    $validatedData = $request->validate([
        'project_id' => 'required|exists:projects,id',
        'project_manager_ids' => 'required|array',
        'project_manager_ids.*' => 'exists:users,id'
    ]);

    $project = Project::findOrFail($validatedData['project_id']);

    $existingManagerIds = json_decode($project->project_manager_id, true);
    if (!is_array($existingManagerIds)) {
        $existingManagerIds = $existingManagerIds ? [$existingManagerIds] : [];
    }

    $mergedManagerIds = array_unique(array_merge($existingManagerIds, $validatedData['project_manager_ids']));


    $project->project_manager_id = json_encode($mergedManagerIds);
    $project->assigned_by = auth()->user()->id;
    $project->save();

    $newlyAssignedIds = array_diff($validatedData['project_manager_ids'], $existingManagerIds);

    $assigner = auth()->user();
    foreach ($newlyAssignedIds as $managerId) {
        $manager = User::find($managerId);
        if ($manager && $manager->email) {
            $mail = (new ProjectAssignedMail($manager, $project, $assigner))
                ->replyTo($assigner->email, $assigner->name);

            //Mail::to($manager->email)->send($mail);
        }
    }

    return response()->json([
        'success' => true,
        'message' => 'Project assigned successfully and emails sent to new managers.',
        'data' => [
            'project_id' => $project->id,
            'project_manager_ids' => $mergedManagerIds,
            'emails_sent_to' => $newlyAssignedIds
        ]
    ]);
}


	public function assignProjectManagerProjectToEmployee(Request $request)
	{
		$projectManagerId = auth()->user()->id;
    $validatedData = $request->validate([
        'project_id' => 'required|exists:projects,id',
        'employee_ids' => 'required|array|min:1',
        'employee_ids.*' => 'exists:users,id'
    ]);

    $project = Project::find($validatedData['project_id']);

    if (!$project) {
        return ApiResponse::error('Invalid project_id. Project does not exist.', [], 404);
    }

    $projectManagerId = auth()->user()->id;

    $insertedData = [];
    $alreadyAssigned = [];

    try {
        foreach ($validatedData['employee_ids'] as $employeeId) {
            $exists = DB::table('project_user')
                   ->where('project_id', $validatedData['project_id'])
                ->where('user_id', $employeeId)
                ->exists();

            if ($exists) {
                $alreadyAssigned[] = $employeeId;
                continue;
            }

            $insertedId = DB::table('project_user')->insertGetId([
                'project_id' => $validatedData['project_id'],
                'user_id' => $employeeId,
				'project_manager_id' => $projectManagerId,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $insertedData[] = [
                'id' => $insertedId,  
                'project_id' => $validatedData['project_id'],
                'user_id' => $employeeId,
				'project_manager_id' => $projectManagerId,
            ];
        }
    } catch (\Exception $e) {
        return ApiResponse::error('Database Error: ' . $e->getMessage(), [], 500);
    }

    $responseMessage = 'Project assigned successfully';
    if (!empty($alreadyAssigned)) {
        $responseMessage .= '. But these users were already assigned: ' . implode(', ', $alreadyAssigned);
    }

    return ApiResponse::success($responseMessage, [
        'project_manager_id' => $projectManagerId,
        'data' => $insertedData
    ]);
	}

	public function getProjectofEmployeeAssignbyProjectManager()
    {
    $projectManagerId = auth()->id();

    $projects = Project::where(function ($query) use ($projectManagerId) {
        $query->whereRaw("JSON_CONTAINS(project_manager_id, ?, '$')", [json_encode($projectManagerId)])
              ->orWhereRaw("JSON_CONTAINS(tl_id, ?, '$')", [json_encode($projectManagerId)]);
    })
    ->with(['assignedEmployees:id,name,email','client:id,name'])
    ->get(['id', 'project_name', 'client_id', 'tl_id', 'deadline', 'project_manager_id'])
    ->map(function ($project) {
        $tlIds = json_decode($project->tl_id, true);

        if (!is_array($tlIds)) {
            $tlIds = [];
        }

        if (!empty($tlIds)) {
            $project->team_leads = User::whereIn('id', $tlIds)->get(['id', 'name']);
        } else {
            $project->team_leads = collect(); 
        }

        return $project;
    });

    if ($projects->isEmpty()) {
        return ApiResponse::error('No projects found for this Project Manager.', [], 404);
    }

    return ApiResponse::success('Projects fetched successfully', [
        'project_manager_id' => $projectManagerId,
        'projects' => $projects
    ]);
    }
public function getemployeeProjects()
{
    $projectManagerId = auth()->user()->id;

    $projects = Project::whereRaw("JSON_CONTAINS(tl_id, ?, '$')", [json_encode($projectManagerId)])
        ->with([
            'assignedEmployees' => function ($query) {
                $query->select('users.id', 'users.name', 'users.email');
            },
            'client:id,name'
        ])
        ->get([
            'id',
            'project_name',
            'client_id',
            'deadline',
            'project_manager_id',
            'project_status',   
            'project_type'   
        ]);

    if ($projects->isEmpty()) {
        return ApiResponse::error('No projects found for this Project Manager.', [], 404);
    }

    return ApiResponse::success('Projects fetched successfully', [
        'project_manager_id' => $projectManagerId,
        'projects' => $projects
    ]);
}

    public function getTlProjects()
    {
        $user = auth()->user();

        $projects = Project::whereRaw("JSON_CONTAINS(tl_id, ?, '$')", [json_encode($user->id)])
            ->with('client', 'assignedBy')
            ->get();

        return ApiResponse::success('Projects fetched successfully', $projects);
    }
    public function getUserProjects()
    {
        try {
            $user = auth()->user();

            $projects = $user->assignedProjects()
                ->with('client')
                ->get()
                ->map(function ($project) {
                    $tagIds = $project->tags_activitys ? json_decode($project->tags_activitys, true) : [];

                    $tags = TagsActivity::whereIn('id', $tagIds)->get(['id', 'name']);

                    return [
                        'id' => $project->id,
                        'project_name' => $project->project_name,
                        'deadline' => $project->deadline,
                        'project_type' => $project->project_type,
                        'project_status' => $project->project_status,
                        'created_at' => $project->created_at ? Carbon::parse($project->created_at)->toDateString() : null,
                        'updated_at' => $project->updated_at ? Carbon::parse($project->updated_at)->toDateString() : null,
                        'client' => $project->client ?? ['message' => 'No Client Found'],
                        'tags_activitys' => $tags,
                        'pivot' => [
                            'user_id' => $project->pivot->user_id ?? null,
                            'project_id' => $project->pivot->project_id ?? null,
                            'assigned_at' => $project->pivot->created_at
                                ? Carbon::parse($project->pivot->created_at)->toDateString()
                                : 'Not Assigned'
                        ]
                    ];
                });

            return ApiResponse::success('User projects fetched successfully', $projects);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user projects',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function getAssignedProjects()
    {
        $user = auth()->user();

        $projects = Project::whereRaw("JSON_CONTAINS(project_manager_id, ?, '$')", [json_encode($user->id)])
            ->with('client', 'assignedBy')
            ->get();

        return ApiResponse::success('Projects fetched successfully', $projects);
    }


public function assignProjectToTL(Request $request): JsonResponse
{
    try {
        $validatedData = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'tl_id' => 'required|array',
            'tl_id.*' => 'exists:users,id'
        ]);
    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    }

    try {
        $project = Project::findOrFail($validatedData['project_id']);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Project not found',
        ], 404);
    }

    $existingTlIds = $project->tl_id ? json_decode($project->tl_id, true) : [];
    $mergedTlIds = array_unique(array_merge($existingTlIds, $validatedData['tl_id']));

    $project->tl_id = json_encode($mergedTlIds);
    $project->assigned_by = auth()->id();
    $project->save();


    $newlyAssignedTlIds = array_diff($validatedData['tl_id'], $existingTlIds);
    $assigner = auth()->user();

    foreach ($newlyAssignedTlIds as $tlId) {
        $tl = User::find($tlId);
        if ($tl && $tl->email) {
            // $mail = (new ProjectAssignedToTLMail($tl, $project, $assigner))
            //     ->replyTo($assigner->email, $assigner->name);

            // Mail::to($tl->email)->send($mail);
        }
    }

    return response()->json([
        'success' => true,
        'message' => 'Project assigned to Team Leaders successfully and emails sent to new TLs.',
        'data' => [
            'project_id' => $project->id,
            'tl_ids' => $mergedTlIds,
            'assigned_by' => $project->assigned_by,
            'emails_sent_to' => $newlyAssignedTlIds
        ]
    ], 200);
}



    public function update(Request $request, $id)
    {
        $project = Project::find($id);
        if (!$project) {
            return ApiResponse::error('Project not found', [], 404);
        }

        try {
            $validatedData = $request->validate([
                'client_id' => 'required|exists:clients,id',
                'project_name' => 'required|string|max:255|unique:projects,project_name,' . $id,
                'project_type' => 'nullable|string|in:Fixed,Hourly',
                'deadline' => 'nullable|date',
                'tags_activitys' => 'nullable|array',
                'technology' => 'nullable|array',
                'tags_activitys.*' => 'integer',
                'project_status' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation Error',
                $e->errors(),
                422
            );
        }

        $project->client_id = $validatedData['client_id'];
        $project->project_name = $validatedData['project_name'];
        $project->project_status = $validatedData['project_status'];
        $project->deadline = $validatedData['deadline'] ?? null;
        $project->technology = isset($validatedData['technology']) ? json_encode($validatedData['technology']) : $project->technology;

        if (isset($validatedData['project_type'])) {
            $project->project_type = $validatedData['project_type'];
        }

        if (isset($validatedData['tags_activitys'])) {
            $project->tags_activitys = json_encode($validatedData['tags_activitys']);
        }

        $project->save();

        return ApiResponse::success('Project updated successfully', new ProjectResource($project));
    }


    public function destroy($id)
    {
        $project = Project::find($id);
        if (!$project) {
            return ApiResponse::error('Project not found', [], 404);
        }
        DB::table('project_user')->where('project_id', $id)->delete();
        DB::table('tasks')->where('project_id', $id)->delete();

        $project->delete();
        return ApiResponse::success('Project deleted successfully',$project,200);
    }

	public function assignUsersToProject(Request $request, $projectId)
	{
		$project = Project::find($projectId);
		if (!$project) {
			return ApiResponse::error('Project not found', [], 404);
		}
		$request->validate([
        'user_ids' => 'required|array',
        'user_ids.*' => 'exists:users,id'
		]);
		$project->assignedUsers()->sync($request->user_ids);
		return ApiResponse::success('Users assigned successfully', $project->load('assignedUsers'));
	}

    public function getAssignedAllProjects()
    {
        try {
                $projects = Project::with([
                    'assignedEmployees:id,name,email',  
                    'client:id,name'                    
                ])->latest()->get();

                $formattedProjects = $projects->map(function ($project) {
                    $managerIds = json_decode($project->project_manager_id, true) ?? [];

                    if (!empty($managerIds)) {
                        $managers = User::whereIn('id', $managerIds)
                            ->get(['id', 'name'])
                            ->map(function ($manager) {
                                return [
                                    'id' => $manager->id,
                                    'name' => $manager->name
                                ];
                            })
                            ->toArray();
                    } else {
                        $managers = [["id" => null, "name" => "Not Assigned to Any Manager"]];
                    }   
                    return [
                        'id' => $project->id,
                        'project_name' => $project->project_name,
                        'project_type' => $project->project_type,
                        'project_status' => $project->project_status,
                        'client_name' => $project->client ? $project->client->name : 'No Client Assigned',
                        'budget' => $project->budget,
                        'deadline' => $project->deadline,
                        'total_hours' => $project->total_hours,
                        'assigned_users' => $project->assignedEmployees->map(function ($user) {
                            return [
                                'id' => $user->id,
                                'name' => $user->name,
                                'email' => $user->email,
                            ];
                        }),
                        'project_managers' => $managers
                    ];
                });

                return response()->json([
                    'success' => true,
                    'message' => 'Project assignments fetched successfully.',
                    'data' => $formattedProjects
                ]);

            } catch (\Exception $e) {
                Log::error('Error fetching project assignments: ' . $e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Internal Server Error',
                    'error' => $e->getMessage()
                ], 500);
            }
    }


    /*public function getProjectEmployee()
    {
        $user = auth()->user();
        $projects = Project::where('project_manager_id', $user->id)
            ->with([
                'client:id,name', // Get only client id & name
                'projectManager:id,name' // Get project manager id & name
            ])
            ->get(['id', 'project_name', 'client_id']); // Fetch only required fields

        return ApiResponse::success('Projects fetched successfully', $projects);
        //return response()->json(['message' => 'Test']);
    }*/

    public function getProjectManagerTl()
    {
        $user = auth()->user(); 

        if (!$user->team_id) {
            return response()->json([
                'success' => false,
                'message' => 'Team ID not found for this user.',
                'data' => []
            ]);
        }

        // Fetch all employees in the same team, excluding the logged-in manager
        // $employees = User::where('id', '!=', $user->id)
        //     ->where('role_id', '=', 6)
        //     ->select('id', 'name', 'email', 'profile_pic', 'role_id')
        //     ->get();

        $employees = User::where('id', '!=', $user->id)
        ->whereHas('role', function ($query) {
            $query->where('name', 'TL');
        })
        ->select('id', 'name', 'email', 'profile_pic', 'role_id')
        ->get();

        return response()->json([
            'success' => true,
            'message' => $employees->isEmpty() ? 'No employees found for this team.' : 'Employees fetched successfully',
            'team_id' => $user->team_id,
            'project_manager_id' => $user->id,
            'employees' => $employees
        ]);
    }

    public function getTlEmployee()
    {
        $user = auth()->user(); 

        if (!$user->team_id) {
            return response()->json([
                'success' => false,
                'message' => 'Team ID not found for this user.',
                'data' => []
            ]);
        }

        $employees = User::where('id', '!=', $user->id)
            ->where([   ['team_id', '=', $user->team_id],    ['role_id', '=', 7], ])
            ->select('id', 'name', 'email', 'profile_pic', 'role_id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => $employees->isEmpty() ? 'No employees found for this team.' : 'Employees fetched successfully',
            'team_id' => $user->team_id,
            'tl_id' => $user->id,
            'employees' => $employees
        ]);
    }


    public function removeProjectManagers(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'project_id' => 'required|exists:projects,id',
                'manager_ids' => 'required|array|min:1',
                'manager_ids.*' => 'integer|exists:users,id'
            ]);

            $project = Project::find($validatedData['project_id']);

            $existingManagers = json_decode($project->project_manager_id, true) ?? [];

            $updatedManagers = array_diff($existingManagers, $validatedData['manager_ids']);

            $affectedRows = DB::table('project_user')
                ->where('project_id', $validatedData['project_id'])
                ->whereIn('project_manager_id', $validatedData['manager_ids'])
                ->update(['project_manager_id' => null]);

            if (empty($updatedManagers)) {
                $project->project_manager_id = null;
            } else {
                $project->project_manager_id = json_encode(array_values($updatedManagers));
            }

            $project->save();

            return response()->json([
                'success' => true,
                'message' => 'Project managers removed successfully.',
                'updated_rows' => $affectedRows,
                'remaining_managers' => $project->project_manager_id ? json_decode($project->project_manager_id) : null
            ]);

        } catch (\Exception $e) {
            Log::error('Error removing project managers: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function removeprojecttl($project_id, $tl_id)
    {
        if (!is_numeric($project_id) || !is_numeric($tl_id)) {
            return response()->json(['error' => 'Invalid parameters'], 422);
        }

        $project = Project::find($project_id);

        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $tlIds = json_decode($project->tl_id, true);

        if (!is_array($tlIds)) {
            $tlIds = [];
        }

        $updatedTlIds = array_filter($tlIds, fn($id) => $id != $tl_id);

        $project->tl_id = json_encode(array_values($updatedTlIds));
        $project->save();

        return response()->json([
            'success' => true,
            'message' => 'Team Lead removed successfully.',
            'data' => [
                'project_id' => $project->id,
                'updated_tl_id' => $project->tl_id
            ]
        ]);
    }

    public function removeprojectemployee($project_id, $user_id)
    {
        $project = Project::find($project_id);

        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $project->assignedUsers()->detach($user_id);

        return response()->json([
            'success' => true,
            'message' => 'User removed from project successfully.',
        ]);
    }



    public function GetFullProjectManangerData(Request $request)
    {
        $projectId = $request->input('project_id');

        if ($projectId) {
            $projects = Project::where('id', $projectId)->get();

            if ($projects->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project Id not exist'
                ], 404);
            }
        } else {
            $projects = Project::select('id', 'project_name', 'total_hours', 'total_working_hours', 'project_manager_id', 'tl_id')->get();
        }

        $approvedPerformas = PerformaSheet::where('status', 'Approved')->get();

        $performaHours = [];
        foreach ($approvedPerformas as $sheet) {
            $data = is_array($sheet->data) ? $sheet->data : json_decode($sheet->data, true);
            if (!isset($data['project_id'], $data['time'])) continue;

            $projId = $data['project_id'];
            $userId = $sheet->user_id;
            $time = floatval($data['time']);

            if (!isset($performaHours[$projId][$userId])) {
                $performaHours[$projId][$userId] = 0;
            }
            $performaHours[$projId][$userId] += $time;
        }

        $projectUserMap = DB::table('project_user')->get()->groupBy('project_id');
        $users = User::select('id', 'name', 'email', 'role_id', 'team_id')->get()->keyBy('id');
        $teams = DB::table('teams')->pluck('name', 'id');

        $data = $projects->map(function ($project) use ($users, $projectUserMap, $performaHours, $teams) {
            $assignedUsers = $projectUserMap[$project->id] ?? collect();

            $managers = [];
            $tls = [];
            $employees = [];

            $managerIDs = is_array($project->project_manager_id)
                ? $project->project_manager_id
                : json_decode($project->project_manager_id, true);

            $tlIDs = is_array($project->tl_id)
                ? $project->tl_id
                : json_decode($project->tl_id, true);

            $managerIDs = is_array($managerIDs) ? $managerIDs : [];
            $tlIDs = is_array($tlIDs) ? $tlIDs : [];

            foreach ($managerIDs as $managerId) {
                $user = $users[$managerId] ?? null;
                if ($user) {
                    $managers[] = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'worked_hours' => round($performaHours[$project->id][$user->id] ?? 0, 2),
                        'department' => $teams[$user->team_id] ?? null,
                    ];
                }
            }

            foreach ($tlIDs as $tlId) {
                $user = $users[$tlId] ?? null;
                if ($user) {
                    $tls[] = [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'worked_hours' => round($performaHours[$project->id][$user->id] ?? 0, 2),
                        'department' => $teams[$user->team_id] ?? null,
                    ];
                }
            }

            foreach ($assignedUsers as $row) {
                $user = $users[$row->user_id] ?? null;

                if (
                    !$user ||
                    in_array($user->id, $managerIDs) ||
                    in_array($user->id, $tlIDs)
                ) continue;

                $employees[] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'worked_hours' => round($performaHours[$project->id][$user->id] ?? 0, 2),
                    'department' => $teams[$user->team_id] ?? null,
                ];
            }

            $workedHours = array_sum($performaHours[$project->id] ?? []);
            $remainingHours = Project::where('id', $project->id)
                ->value('remaining_hours');
            return [
                'project_id' => $project->id,
                'project_name' => $project->project_name,
                'total_hours' => (float)$project->total_hours,
                'worked_hours' => round($workedHours, 2),
                'remaining_hours' => $remainingHours,
                'managers' => $managers,
                'tls' => $tls,
                'employees' => $employees,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }






    public function totaldepartmentProject()
    {

        $projects = Project::all();

        $teamProjectMap = [];

        foreach ($projects as $project) {
            $managerIds = json_decode($project->project_manager_id, true);

            if (empty($managerIds)) {
                $teamProjectMap['Not Assigned'] = ($teamProjectMap['Not Assigned'] ?? 0) + 1;
                continue;
            }

            $managers = User::with('team:id,name')
                ->whereIn('id', $managerIds)
                ->get();

            $teamNames = $managers
                ->filter(fn ($user) => $user->team)
                ->pluck('team.name')
                ->unique();

            if ($teamNames->isEmpty()) {
                $teamProjectMap['Not Assigned'] = ($teamProjectMap['Not Assigned'] ?? 0) + 1;
            } else {
                foreach ($teamNames as $teamName) {
                    $teamProjectMap[$teamName] = ($teamProjectMap[$teamName] ?? 0) + 1;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $teamProjectMap,
        ]);
    }



    public function viewProjectData($id)
    {
        $project = Project::with([
            'client',
            'salesTeam',
            'projectManager',
            'assignedEmployees',
            'assignedUsers',
            'teamLead',
            'assignedBy',
            'tasks'
        ])->find($id);
        if (!$project) {
            return response()->json(['error' => 'Project not found'], 404);
        }
        $performaSheets = PerformaSheet::where('data->project_id', $id)->get();
        $project->performa_sheets = $performaSheets;
        return response()->json($project);
    }


public function get_project_status_by_tl_and_pm(Request $request)
{
    if (!auth()->check()) {
        return response()->json([
            'success' => false,
            'message' => 'User not authenticated'
        ], 401);
    }

    $user = auth()->user();

    if ($user->role_id != 1) {
        return response()->json([
            'success' => false,
            'message' => 'Access Only Super Admin!'
        ], 403);
    }

    $request->validate([
        'user_id' => 'required|integer|exists:users,id',
        'type' => 'required|string|in:tl,pm'
    ]);

    $userId = (int) $request->user_id;
    $type = $request->type;

    $query = \App\Models\Project::query();

    if ($type == 'tl') {
        $query->whereJsonContains('tl_id', $userId);
    } elseif ($type == 'pm') {
        $query->whereJsonContains('project_manager_id', $userId);
    }

    $projects = $query->get();

    if ($projects->isEmpty()) {
        return response()->json([
            'success' => true,
            'message' => 'No projects found for this user.',
            'data' => []
        ], 200);
    }

    return response()->json([
        'success' => true,
        'message' => 'Projects fetched successfully.',
        'data' => $projects
    ], 200);
}

}
