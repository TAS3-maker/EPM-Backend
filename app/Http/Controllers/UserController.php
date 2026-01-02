<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Team;
use App\Models\PerformaSheet;
use App\Models\Project;
use App\Models\Role;
use App\Http\Resources\UserResource;
use App\Http\Helpers\ApiResponse;
use App\Mail\SendEmployeeCredentials;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Models\Permission;
class UserController extends Controller
{

    public function checkToken(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $token = $request->bearerToken();
            if ($user->is_active == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is inactive. Please contact admin.'
                ], 401);
            }
            return response()->json([
                'success' => true,
                'message' => 'Token is valid',
                'user' => $user,
                'token' => $token
            ]);
        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token has expired. Please log in again.'
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token is invalid. Please log in again.'
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token is missing or not provided.'
            ], 401);
        }
    }

    // public function store(Request $request)
    // {
    //     try {
    //         // Validation
    //         $validatedData = $request->validate([
    //             'name' => 'required|string|max:255',
    //             'email' => 'required|email|unique:users',
    //             'password' => 'required|min:6',
    //             // 'team_id' => 'nullable|exists:teams,id',
    //             'team_id' => 'nullable',
    //             // 'team_id.*' => 'exists:teams,id',
    //             'phone_num' => 'required|string|min:10|max:15|unique:users,phone_num',
    //             'emergency_phone_num' => 'nullable|string|min:10|max:15|unique:users,emergency_phone_num',
    //             'address' => 'nullable|string',
    //             'role_id' => 'required|exists:roles,id',
    //             'tl_id' => 'required_if:role_id,7|nullable|exists:users,id',
    //             'profile_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
    //             'employee_id' => 'required|string|unique:users,employee_id',
    //         ], [
    //             'employee_id.required' => 'Employee ID is required.',
    //             'employee_id.unique' => 'This employee ID already exists. Please choose a different one.',
    //             'tl_id.required_if' => 'Team Leader is required for employees.',
    //             'tl_id.exists' => 'Selected Team Leader does not exist.',
    //         ]);
    //         $teamIds = $request->team_id;
    //         if (is_string($teamIds)) {
    //             $teamIds = array_filter(array_map('intval', explode(',', $teamIds)));
    //         }
    //         if (!is_array($teamIds)) {
    //             $teamIds = [];
    //         }
    //         $existingTeams = Team::whereIn('id', $teamIds)->pluck('id')->toArray();
    //         $missing = array_diff($teamIds, $existingTeams);

    //         if (!empty($missing)) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Team does not exist!'
    //             ], 422);
    //         }

    //         $user = User::create([
    //             'name' => $validatedData['name'],
    //             'email' => $validatedData['email'],
    //             'address' => $validatedData['address'] ?? null,
    //             'phone_num' => $validatedData['phone_num'] ?? null,
    //             'emergency_phone_num' => $validatedData['emergency_phone_num'] ?? null,
    //             'password' => Hash::make($validatedData['password']),
    //             // 'team_id' => $validatedData['team_id'] ?? null,
    //             'team_id' => $teamIds ?? null,
    //             'role_id' => $validatedData['role_id'],
    //             'tl_id' => $validatedData['tl_id'] ?? null,
    //             'employee_id' => $validatedData['employee_id'] ?? null,
    //         ]);

    //         if ($request->hasFile('profile_pic')) {
    //             $file = $request->file('profile_pic');

    //             if (!Storage::disk('public')->exists('profile_pics')) {
    //                 Storage::disk('public')->makeDirectory('profile_pics');
    //             }

    //             $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
    //             $file->storeAs('profile_pics', $filename, 'public');

    //             $user->profile_pic = $filename;
    //             $user->save();
    //         }
    //         $role_id = $request->role_id;

    //         return ApiResponse::success('User created successfully', new UserResource($user), 201);

    //     } catch (ValidationException $e) {
    //         return ApiResponse::error('Validation failed', $e->errors(), 422);
    //     } catch (\Exception $e) {
    //         \Log::error('Error creating user: ' . $e->getMessage());
    //         return ApiResponse::error('An unexpected error occurred.', ['general' => $e->getMessage()], 500);
    //     }
    // }


    public function store(Request $request)
    {
        try {
            // ================= VALIDATION =================
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users',
                'password' => 'required|min:6',
                'team_id' => 'nullable',
                'phone_num' => 'required|string|min:10|max:15|unique:users,phone_num',
                'emergency_phone_num' => 'nullable|string|min:10|max:15|unique:users,emergency_phone_num',
                'address' => 'nullable|string',
                'role_id' => 'required|exists:roles,id',
                'tl_id' => 'required_if:role_id,7|nullable|exists:users,id',
                'profile_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'employee_id' => 'required|string|unique:users,employee_id',
            ], [
                'employee_id.required' => 'Employee ID is required.',
                'employee_id.unique' => 'This employee ID already exists. Please choose a different one.',
                'tl_id.required_if' => 'Team Leader is required for employees.',
                'tl_id.exists' => 'Selected Team Leader does not exist.',
            ]);
            // ================= TEAM LOGIC =================
            $teamIds = $request->team_id;
            if (is_string($teamIds)) {
                $teamIds = array_filter(array_map('intval', explode(',', $teamIds)));
            }
            if (!is_array($teamIds)) {
                $teamIds = [];
            }
            $existingTeams = Team::whereIn('id', $teamIds)->pluck('id')->toArray();
            $missing = array_diff($teamIds, $existingTeams);
            if (!empty($missing)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Team does not exist!'
                ], 422);
            }
            // ================= USER CREATE =================
            $user = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'address' => $validatedData['address'] ?? null,
                'phone_num' => $validatedData['phone_num'],
                'emergency_phone_num' => $validatedData['emergency_phone_num'] ?? null,
                'password' => Hash::make($validatedData['password']),
                'team_id' => $teamIds,
                'role_id' => $validatedData['role_id'],
                'tl_id' => $validatedData['tl_id'] ?? null,
                'employee_id' => $validatedData['employee_id'],
            ]);
            // ================= PROFILE PIC =================
            if ($request->hasFile('profile_pic')) {
                $file = $request->file('profile_pic');
                if (!Storage::disk('public')->exists('profile_pics')) {
                    Storage::disk('public')->makeDirectory('profile_pics');
                }
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('profile_pics', $filename, 'public');
                $user->profile_pic = $filename;
                $user->save();
            }
            // ================= PERMISSIONS INSERT =================
            $role = Role::find($validatedData['role_id']);
            if ($role && $role->roles_permissions) {
                $rolePermissions = $role->roles_permissions;
                Permission::create([
                    'user_id' => $user->id,
                    'dashboard' => $rolePermissions['dashboard'] ?? 0,
                    'permission' => $rolePermissions['permission'] ?? 0,
                    'employee_management' => $rolePermissions['employee_management'] ?? 0,
                    'roles' => $rolePermissions['roles'] ?? 0,
                    'department' => $rolePermissions['department'] ?? 0,
                    'team' => $rolePermissions['team'] ?? 0,
                    'clients' => $rolePermissions['clients'] ?? 0,
                    'projects' => $rolePermissions['projects'] ?? 0,
                    'assigned_projects_inside_projects_assigned' =>
                        $rolePermissions['assigned_projects_inside_projects_assigned'] ?? 0,
                    'unassigned_projects_inside_projects_assigned' =>
                        $rolePermissions['unassigned_projects_inside_projects_assigned'] ?? 0,
                    'performance_sheets' => $rolePermissions['performance_sheets'] ?? 0,
                    'pending_sheets_inside_performance_sheets' =>
                        $rolePermissions['pending_sheets_inside_performance_sheets'] ?? 0,
                    'manage_sheets_inside_performance_sheets' =>
                        $rolePermissions['manage_sheets_inside_performance_sheets'] ?? 0,
                    'unfilled_sheets_inside_performance_sheets' =>
                        $rolePermissions['unfilled_sheets_inside_performance_sheets'] ?? 0,
                    'manage_leaves' => $rolePermissions['manage_leaves'] ?? 0,
                    'activity_tags' => $rolePermissions['activity_tags'] ?? 0,
                    'leaves' => $rolePermissions['leaves'] ?? 0,
                    'teams' => $rolePermissions['teams'] ?? 0,
                    'leave_management' => $rolePermissions['leave_management'] ?? 0,
                    'project_management' => $rolePermissions['project_management'] ?? 0,
                    'assigned_projects_inside_project_management' =>
                        $rolePermissions['assigned_projects_inside_project_management'] ?? 0,
                    'unassigned_projects_inside_project_management' =>
                        $rolePermissions['unassigned_projects_inside_project_management'] ?? 0,
                    'performance_sheet' => $rolePermissions['performance_sheet'] ?? 0,
                    'performance_history' => $rolePermissions['performance_history'] ?? 0,
                    'projects_assigned' => $rolePermissions['projects_assigned'] ?? 0,
                    'team_reporting' => $rolePermissions['team_reporting'] ?? 0,
                    'leave_reporting' => $rolePermissions['leave_reporting'] ?? 0,
                    'previous_sheets' => $rolePermissions['previous_sheets'] ?? 0,
                    'offline_hours' => $rolePermissions['offline_hours'] ?? 0,
                    'standup_sheet' => $rolePermissions['standup_sheet'] ?? 0,
                ]);
            }
            return ApiResponse::success(
                'User created successfully',
                new UserResource($user),
                201
            );
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation failed', $e->errors(), 422);
        } catch (\Exception $e) {
            \Log::error('Error creating user: ' . $e->getMessage());
            return ApiResponse::error(
                'An unexpected error occurred.',
                ['general' => $e->getMessage()],
                500
            );
        }
    }

    public function index(Request $request)
    {
        $users = User::with('role')->orderBy('id', 'desc')->get();
        return ApiResponse::success('Users fetched successfully', UserResource::collection($users));
    }

    public function projectManger()
    {
        $users = User::where('role_id', 5)->where('is_active', 1)->get();
        return ApiResponse::success('Project Manger fetched successfully', UserResource::collection($users));
    }

    public function show($id)
    {
        $user = User::with(['team', 'role'])->where('is_active', 1)->find($id);
        if (!$user) {
            return ApiResponse::error('User not found', [], 404);
        }

        return ApiResponse::success('User details fetched successfully', new UserResource($user));
    }

    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return ApiResponse::error('User not found', [], 404);
        }

        $imagePath = 'profile_pics/' . $user->profile_pic;

        if ($user->profile_pic && Storage::disk('public')->exists($imagePath)) {
            Storage::disk('public')->delete($imagePath);
        }
        $user->leaves()->delete();
        $user->delete();

        return ApiResponse::success('User deleted successfully');
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return ApiResponse::error('User not found', [], 404);
        }

        try {
            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $id,
                'phone_num' => 'nullable|string|min:10|max:15|unique:users,phone_num,' . $id,
                'emergency_phone_num' => 'nullable|string|min:10|max:15|unique:users,emergency_phone_num,' . $id,
                'address' => 'nullable|string',
                // 'team_id' => 'nullable|exists:teams,id',
                'team_id' => 'nullable',
                'role_id' => 'nullable|exists:roles,id',
                'profile_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'password' => 'sometimes|min:6|confirmed',
                'is_active' => 'nullable|in:0,1',
            ]);
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation Error', $e->errors(), 422);
        }

        $teamIds = $request->team_id;
        if (is_string($teamIds)) {
            $teamIds = array_filter(array_map('intval', explode(',', $teamIds)));
        }
        if (!is_array($teamIds)) {
            $teamIds = [];
        }

        // Check if all team IDs exist
        $existingTeams = Team::whereIn('id', $teamIds)->pluck('id')->toArray();
        $missing = array_diff($teamIds, $existingTeams);
        if (!empty($missing)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more teams do not exist: ' . implode(',', $missing)
            ], 422);
        }

        $tlId = null;

        if (!empty($teamIds)) {
            $userWithRole6 = User::whereJsonContains('team_id', $teamIds)->where('is_active', 1)
                ->where('role_id', 6)
                ->first();

            if ($userWithRole6) {
                $tlId = $userWithRole6->id;
            }
        }

        // Update fields
        $user->name = $validatedData['name'] ?? $user->name;
        $user->email = $validatedData['email'] ?? $user->email;
        $user->phone_num = $validatedData['phone_num'] ?? $user->phone_num;
        $user->emergency_phone_num = $validatedData['emergency_phone_num'] ?? $user->emergency_phone_num;
        $user->address = $validatedData['address'] ?? $user->address;
        $user->team_id = !empty($teamIds) ? ($teamIds) : $user->team_id;
        $user->role_id = $validatedData['role_id'] ?? $user->role_id;
        if (!empty($teamIds)) {
            $user->tl_id = $tlId;
        }
        if (isset($validatedData['password'])) {
            $user->password = Hash::make($validatedData['password']);
        }

        if ($request->hasFile('profile_pic')) {
            if ($user->profile_pic && Storage::disk('public')->exists('profile_pics/' . $user->profile_pic)) {
                Storage::disk('public')->delete('profile_pics/' . $user->profile_pic);
            }

            // New image store
            $file = $request->file('profile_pic');
            $filename = time() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('profile_pics', $filename, 'public');
            $user->profile_pic = $filename;
        }
        $user->is_active = $validatedData['is_active'] ?? 1;

        $user->save();
        return ApiResponse::success('User updated successfully', new UserResource($user->fresh()));
    }


    public function GetFullProileEmployee(Request $request, $id)
    {
        $user = User::with('role')->find($id);
        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)->toDateString()
            : null;

        $endDate = $request->end_date
            ? Carbon::parse($request->end_date)->toDateString()
            : null;


        if (!$user) {
            return ApiResponse::error('User not found', [], 404);
        }

        $projectRelations = DB::table('project_relations')
            ->join('projects_master', 'project_relations.project_id', '=', 'projects_master.id')
            ->select(
                'project_relations.project_id',
                'projects_master.project_name',
                'projects_master.project_status',
                'project_relations.assignees',
                'project_relations.created_at',
                'project_relations.updated_at'
            )
            ->whereRaw(
                'JSON_CONTAINS(project_relations.assignees, ?)',
                [json_encode((int) $id)]
            )
            ->get();

        $allAssigneeIds = collect($projectRelations)
            ->pluck('assignees')
            ->flatMap(fn($a) => json_decode($a, true) ?: [])
            ->unique()
            ->values()
            ->toArray();

        $users = DB::table('users')
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->whereIn('users.id', $allAssigneeIds)
            ->select(
                'users.id',
                'users.name',
                'roles.name as role_name'
            )
            ->get()
            ->keyBy('id');

        $performaSheets = DB::table('performa_sheets')
            ->where('user_id', $id)
            ->where('status', 'approved')
            ->get();

        $activityData = [];

        foreach ($performaSheets as $row) {
            $decoded = json_decode($row->data, true);

            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }

            $entries = isset($decoded[0]) ? $decoded : [$decoded];

            foreach ($entries as $entry) {

                if (isset($entry['date']) && ($startDate || $endDate)) {
                    $entryDate = $entry['date'];

                    if ($startDate && $entryDate < $startDate) {
                        continue;
                    }

                    if ($endDate && $entryDate > $endDate) {
                        continue;
                    }
                }

                if (!isset($entry['activity_type'], $entry['time'])) {
                    continue;
                }

                $activityType = $entry['activity_type'];
                $projectId = $entry['project_id'] ?? null;
                $time = $entry['time'];

                $timeParts = explode(':', $time);
                if (count($timeParts) !== 2) {
                    continue;
                }

                $minutes = ((int) $timeParts[0] * 60) + (int) $timeParts[1];

                if (!isset($activityData[$projectId])) {
                    $activityData[$projectId] = [];
                }

                if (!isset($activityData[$projectId][$activityType])) {
                    $activityData[$projectId][$activityType] = 0;
                }

                $activityData[$projectId][$activityType] += $minutes;
            }
        }


        $projectUserData = [];

        foreach ($projectRelations as $relation) {

            $assignees = json_decode($relation->assignees, true);
            if (!is_array($assignees)) {
                continue;
            }

            $pm = collect($assignees)
                ->map(fn($uid) => $users[$uid] ?? null)
                ->filter()
                ->firstWhere('role_name', 'Project Manager');

            $activities = [];

            if (isset($activityData[$relation->project_id])) {
                foreach ($activityData[$relation->project_id] as $type => $minutes) {
                    $h = floor($minutes / 60);
                    $m = $minutes % 60;

                    $activities[] = [
                        'activity_type' => $type,
                        'total_hours' => sprintf('%02d:%02d', $h, $m),
                    ];
                }
            }

            $projectUserData[] = (object) [
                'project_id' => $relation->project_id,
                'project_name' => $relation->project_name,
                'project_status' => $relation->project_status,
                'project_manager_id' => $pm->id ?? null,
                'project_manager_name' => $pm->name ?? null,
                'user_id' => $id,
                'activities' => $activities,
                'created_at' => $relation->created_at,
                'updated_at' => $relation->updated_at,
            ];
        }

        if (isset($activityData[null])) {
            $activities = [];

            foreach ($activityData[null] as $type => $minutes) {
                $h = floor($minutes / 60);
                $m = $minutes % 60;

                $activities[] = [
                    'activity_type' => $type,
                    'total_hours' => sprintf('%02d:%02d', $h, $m),
                ];
            }

            $projectUserData[] = (object) [
                'project_id' => null,
                'project_name' => 'Inhouse',
                'project_manager_id' => null,
                'project_manager_name' => null,
                'user_id' => $id,
                'activities' => $activities,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return ApiResponse::success('User details fetched successfully', [
            'user' => new UserResource($user),
            'project_user' => $projectUserData,
        ]);
    }




    public function getUserCountByTeam()
    {
        $teams = Team::all();
        $users = User::whereNotNull('team_id')->where('is_active', 1)->get();

        $teamUserMap = [];

        foreach ($teams as $team) {
            $teamUserMap[$team->name . ' Users'] = 0;
        }

        foreach ($users as $user) {
            if ($user->team_id && isset($teamUserMap[$user->team->name . ' Users'])) {
                $teamUserMap[$user->team->name . ' Users'] += 1;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $teamUserMap
        ]);
    }

    public function getMyProfile($id)
    {
        $user = User::with(['role'])->find($id);

        $teamNames = [];
        if (is_array($user->team_id) && count($user->team_id) > 0) {
            $teams = Team::whereIn('id', $user->team_id)->get();
            $teamNames = $teams->pluck('name')->toArray();
        }
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }
        $user['team_names'] = $teamNames;
        return response()->json([
            'success' => true,
            'message' => 'Profile fetched successfully',
            'data' => $user
        ]);
    }

    public function importUsers(Request $request)
    {
        $request->validate([
            'file' => 'required',
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        $users = [];
        while (($row = fgetcsv($handle)) !== false) {
            $users[] = array_combine($header, $row);
        }

        $imported = 0;
        $skipped = 0;
        $skippedDetails = [];

        $rolesMap = [
            'Super Admin' => 1,
            'Admin' => 2,
            'HR' => 3,
            'Billing Manager' => 4,
            'Project Manager' => 5,
            'TL' => 6,
            'Team' => 7,
            'Sales' => 14,
        ];

        $teamsMap = [
            'Frontend development' => 1,
            'Backend development' => 2,
            'SEO' => 3,
            'Business Development' => 4,
        ];

        foreach ($users as $data) {
            try {
                $roleId = $rolesMap[$data['roles']] ?? null;
                if (!$roleId) {
                    $skipped++;
                    $skippedDetails[] = ['row' => $data, 'reason' => 'Invalid role: ' . $data['roles']];
                    continue;
                }
                $teamId = $teamsMap[$data['team']] ?? null;
                if (User::where('email', $data['email'])->exists()) {
                    $skipped++;
                    $skippedDetails[] = ['row' => $data, 'reason' => 'Email already exists'];
                    continue;
                }
                if (User::where('phone_num', $data['phone_num'])->exists()) {
                    $skipped++;
                    $skippedDetails[] = ['row' => $data, 'reason' => 'Phone number already exists'];
                    continue;
                }
                if (
                    !empty($data['emergency_phone_num']) &&
                    User::where('emergency_phone_num', $data['emergency_phone_num'])->exists()
                ) {
                    $skipped++;
                    $skippedDetails[] = ['row' => $data, 'reason' => 'Emergency phone already exists'];
                    continue;
                }
                $tlId = null;
                if (!empty($teamId)) {
                    if (is_string($teamId)) {
                        $teamId = array_filter(array_map('intval', explode(',', $teamId)));
                    }
                    if (!is_array($teamId)) {
                        $teamId = [];
                    }
                    $existingTl = User::where('role_id', 6)
                        ->where(function ($q) use ($teamId) {
                            foreach ($teamId as $t) {
                                $q->orWhereRaw(
                                    'JSON_CONTAINS(team_id, ?)',
                                    [json_encode($t)]
                                );
                            }
                        })
                        ->first();
                    if ($existingTl) {
                        $tlId = $existingTl->id;
                    }
                }
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password'] ?? 'password123'), // default if blank
                    'address' => $data['address'] ?? null,
                    'phone_num' => $data['phone_num'],
                    'emergency_phone_num' => $data['emergency_phone_num'] ?? null,
                    'role_id' => $roleId,
                    'team_id' => $teamId,
                    'tl_id' => $tlId,
                ]);
                $imported++;
                try {
                    $roleName = $data['roles'];
                    $teamName = $data['team'] ?? null;

                    // Mail::to($data['email'])->send(new SendEmployeeCredentials(
                    //     $data['email'],
                    //     $data['password'] ?? 'password123',
                    //     $roleName,
                    //     $teamName
                    // ));
                } catch (\Exception $mailError) {
                    \Log::error('Email failed: ' . $data['email'] . ' - ' . $mailError->getMessage());
                }
            } catch (\Exception $e) {
                $skipped++;
                $skippedDetails[] = ['row' => $data, 'reason' => 'Error: ' . $e->getMessage()];
                continue;
            }
        }
        return response()->json([
            'success' => true,
            'message' => 'User import completed.',
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'skipped_details' => $skippedDetails,
        ]);
    }
    public function get_all_tl(Request $request)
    {
        try {
            $request->validate([
                'team_id' => 'required|integer'
            ]);

            $teamId = intval($request->team_id);
            $teamIds = [$teamId];

            $tls = \App\Models\User::where('role_id', 6)->where('is_active', 1)
                ->where(function ($q) use ($teamIds) {
                    foreach ($teamIds as $t) {
                        if ($t !== null) {
                            $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                        }
                    }
                })
                ->get();
            if ($tls->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Team Leaders found for this team.',
                    'data' => []
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Team Leaders fetched successfully',
                'data' => $tls
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function DeleteProfilepic(Request $request)
    {
        $user = $request->user();

        if (!$user->profile_pic || $user->profile_pic === 'demo-image.png') {
            return response()->json([
                'success' => false,
                'message' => 'Profile picture already default.'
            ], 200);
        }

        if (Storage::disk('public')->exists('profile_pics/' . $user->profile_pic)) {
            Storage::disk('public')->delete('profile_pics/' . $user->profile_pic);
        }

        $user->profile_pic = 'demo-image.png';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile picture reset to default image.',
            'profile_pic' => asset('storage/profile_pics/demo-image.png')
        ]);
    }

    public function getTeamMembers(Request $request)
    {
        $currentUser = auth()->user();
        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Current user not found',
                'data' => []
            ], 404);
        }

        $tlId = $currentUser->id;

        $teamMembers = User::where('tl_id', $tlId)->where('is_active', 1)->get();
        if ($teamMembers->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No team members found for this Team Leader.',
                'data' => []
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Team members fetched successfully',
            'data' => $teamMembers
        ]);
    }


}
