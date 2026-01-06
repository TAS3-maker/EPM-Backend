<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LeavePolicy;
use App\Models\Project;
use App\Models\User;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\ProjectResource;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use App\Mail\LeaveStatusUpdateMail;

use Illuminate\Support\Facades\Mail;
use App\Mail\ProjectAssignedMail;
use Illuminate\Support\Facades\Storage;
use LDAP\Result;

class LeaveController extends Controller
{
    public function AddLeave(Request $request)
    {
         if ($request->user_id) {
            $user_id = $request->user_id;
        } else {
            $user = auth()->user();
            $user_id = $user->id;
        }

        $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'leave_type' => 'required|in:Full Leave,Short Leave,Half Day,Multiple Days Leave',
            'reason' => 'required',
            'status' => 'in:Pending,Approved,Rejected',

            'start_time' => 'required_if:leave_type,Short Leave|string',
            'end_time' => 'required_if:leave_type,Short Leave|string',

            'halfday_period' => [
                'nullable',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->leave_type === 'Half Day') {
                        if (!$value) {
                            $fail('The halfday_period field is required when leave_type is Half Day.');
                        } elseif (!in_array(strtolower($value), ['morning', 'afternoon'])) {
                            $fail('Halfday period must be either morning or afternoon.');
                        }
                    }
                }
            ],

            'documents' => 'nullable|mimes:jpg,jpeg,png,pdf,docx|max:10240'
        ]);

        if (in_array($request->leave_type, ['Full Leave', 'Short Leave', 'Half Day'])) {
            $endDate = $request->start_date;
        } elseif ($request->leave_type === 'Multiple Days Leave') {
            if (!$request->end_date) {
                return response()->json([
                    'success' => false,
                    'message' => 'End date is required for Multiple Days Leave'
                ], 400);
            }
            $endDate = $request->end_date;
        }
        $hours = null;

        if ($request->leave_type === 'Short Leave') {
            $hours = trim($request->start_time) . ' to ' . trim($request->end_time);
        }

        $halfdayPeriod = ($request->leave_type === 'Half Day')
            ? strtolower($request->halfday_period)
            : null;

        $existingLeaves = LeavePolicy::where('user_id', $user_id)
            ->where('status', '!=', 'Rejected')
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $request->start_date)
            ->get();

        foreach ($existingLeaves as $leave) {

            if (in_array($leave->leave_type, ['Full Leave', 'Multiple Days Leave'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a full day leave for this date.'
                ], 409);
            }

            if (in_array($request->leave_type, ['Full Leave', 'Multiple Days Leave'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot apply full day leave when another leave exists.'
                ], 409);
            }

            if (
                $leave->leave_type === 'Half Day' &&
                $request->leave_type === 'Half Day' &&
                $leave->halfday_period === strtolower($request->halfday_period)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Half day leave already exists for this period.'
                ], 409);
            }

            if (
                $leave->leave_type === 'Short Leave' &&
                $request->leave_type === 'Short Leave'
            ) {
                if (
                    !(
                        $request->end_time <= $leave->start_time ||
                        $request->start_time >= $leave->end_time
                    )
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Short leave time overlaps with existing leave.'
                    ], 409);
                }
            }
        }


        $leave = LeavePolicy::create([
            'user_id' => $user_id,
            'start_date' => $request->start_date,
            'end_date' => $endDate,
            'leave_type' => $request->leave_type,
            'reason' => $request->reason,
            'status' => $request->status ?? 'Pending',
            'hours' => $hours,
            'halfday_period' => $halfdayPeriod,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
        ]);

        if ($request->hasFile('documents')) {
            $file = $request->file('documents');

            if (!Storage::disk('public')->exists('leaves')) {
                Storage::disk('public')->makeDirectory('leaves');
            }

            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('leaves', $filename, 'public');

            $leave->documents = $filename;
            $leave->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Leave request submitted successfully',
            'data' => $leave,
            'url' => !empty($filename) ? asset('storage/' . $filename) : '',
        ]);
    }
    public function getallLeavesForHr()
    {
        $leaves = LeavePolicy::with('user:id,name')
            ->latest()->get();

        if ($leaves->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No leaves found',
                'data' => []
            ]);
        }

        $leaveData = $leaves->map(function ($leave) {
            return [
                'id' => $leave->id,
                'user_id' => $leave->user_id,
                'user_name' => $leave->user->name ?? 'Deleted User',
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'leave_type' => $leave->leave_type,
                'reason' => $leave->reason,
                'status' => $leave->status,
                'hours' => $leave->hours,
                'halfday_period' => $leave->halfday_period,
                'documents' => $leave->documents,
                'created_at' => $leave->created_at,
                'updated_at' => $leave->updated_at
            ];
        });
        return response()->json([
            'success' => true,
            'data' => $leaveData
        ]);
    }

    public function getLeavesByemploye(Request $request)
    {
        $user_id = $request->filled('user_id')
            ? $request->user_id
            : auth()->id();
        $leaves = LeavePolicy::with('user:id,name')
            ->where('user_id', $user_id)
            ->latest()
            ->get();

        if ($leaves->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No leaves found for this user',
                'data' => []
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $leaves
        ]);
    }

    public function showmanagerLeavesForTeamemploye(Request $request)
    {
        $user = auth()->user();
        $team_id = $user->team_id ?? [];

        $isTeamLead = $user->hasRole(6);
        $isManager = $user->hasRole(5);

        $managerInfo = [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $isTeamLead ? 'Team Lead' : 'Manager',
            'team_id' => $user->team_id,
        ];

        $employeesQuery = User::where('id', '!=', $user->id)
            ->where('is_active', '1')
            ->where(function ($q) use ($team_id) {
                foreach ($team_id as $t) {
                    if ($t !== null) {
                        $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                    }
                }
            });

        // If Team Lead → only employees
        if ($isTeamLead) {
            $employeesQuery->whereRaw(
                'JSON_CONTAINS(role_id, ?)',
                [json_encode(7)]
            );
        }

        $employees = $employeesQuery->get();

        $leaves = LeavePolicy::with('user:id,name,team_id')
            ->whereIn('user_id', $employees->pluck('id'))
            ->whereNotNull('start_date')
            ->latest('start_date')
            ->get();

        if ($leaves->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No leaves found for your team.',
                'data' => []
            ]);
        }

        $leaveData = $leaves->map(function ($leave) {
            return [
                'id' => $leave->id,
                'user_id' => $leave->user_id,
                'user_name' => $leave->user->name,
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'leave_type' => $leave->leave_type,
                'reason' => $leave->reason,
                'status' => $leave->status,
                'hours' => $leave->hours,
                'halfday_period' => $leave->halfday_period,
                'documents' => $leave->documents,
                'created_at' => $leave->created_at,
                'updated_at' => $leave->updated_at
            ];
        });

        return response()->json([
            'success' => true,
            'manager' => $managerInfo,
            'data' => $leaveData
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

                $employee = User::where('id', $employeeId)
                    ->where('is_active', 1)
                    ->first();
                $tlUser = null;

                if ($employee && isset($employee->tl_id)) {
                    $tlUser = User::where('id', $employee->tl_id)
                        ->where('is_active', 1)
                        ->first();
                }

                if ($tlUser && $tlUser->email) {
                    $mail = new ProjectAssignedMail($project, $employee, auth()->user());
                    // Mail::to($tlUser->email)->send($mail);
                }
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

    public function approveLeave(Request $request)
    {
        $current_user = auth()->user();

        $request->validate([
            'id' => 'required|exists:leavespolicy,id',
            'status' => 'required|in:Approved,Rejected,approved,rejected',
        ]);

        $managerName = $current_user->name;
        $managerRole = $current_user->role->name ?? 'Manager';

        $leave = LeavePolicy::find($request->id);

        if (!$leave) {
            return response()->json([
                'status' => false,
                'message' => 'Leave not found'
            ], 404);
        }

        $finalStatus = ucfirst(strtolower($request->status));

        $leave->status = $finalStatus;
        $leave->approved_bymanager = $current_user->id;
        $leave->save();

        $user = User::where('id', $leave->user_id)
            ->where('is_active', 1)
            ->first();


        if ($user && $user->email) {
            // Mail::to($user->email)->send(
            //     new LeaveStatusUpdateMail($user, $leave, $managerName, $managerRole)
            // );

        }

        return response()->json([
            'status' => true,
            'message' => "Leave status updated to {$finalStatus} and mail sent",
            'data' => $leave
        ]);
    }

    public function getallLeavesbyUser()
    {
        $currentUser = Auth::user();
        $teamIds = $currentUser->team_id ?? [];

        $leavesQuery = LeavePolicy::with('user:id,name,role_id,team_id')
            ->latest();

        if ($currentUser->hasRole(7)) {
            $leavesQuery->where('user_id', $currentUser->id);
        } elseif ($currentUser->hasRole(6)) {
            $leavesQuery->whereHas('user', function ($q) use ($teamIds) {
                $q->whereRaw('JSON_CONTAINS(role_id, ?)', [json_encode(7)])
                    ->where(function ($sub) use ($teamIds) {
                        foreach ($teamIds as $teamId) {
                            $sub->orWhereJsonContains('team_id', $teamId);
                        }
                    });
            });
        } elseif ($currentUser->hasRole(5)) {
            $leavesQuery->whereHas('user', function ($q) use ($teamIds) {
                $q->where(function ($r) {
                    $r->whereRaw('JSON_CONTAINS(role_id, ?)', [json_encode(6)])
                        ->orWhereRaw('JSON_CONTAINS(role_id, ?)', [json_encode(7)]);
                })
                    ->where(function ($sub) use ($teamIds) {
                        foreach ($teamIds as $teamId) {
                            $sub->orWhereJsonContains('team_id', $teamId);
                        }
                    });
            });
        } elseif ($currentUser->hasAnyRole([1, 2, 3, 4])) {
            $leavesQuery->where('user_id', '!=', $currentUser->id);
        } else {
            $leavesQuery->whereHas('user', function ($q) use ($teamIds) {
                $q->whereRaw('JSON_CONTAINS(role_id, ?)', [json_encode(7)])
                    ->where(function ($sub) use ($teamIds) {
                        foreach ($teamIds as $teamId) {
                            $sub->orWhereJsonContains('team_id', $teamId);
                        }
                    });
            });
        }

        $leaves = $leavesQuery->get();

        if ($leaves->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No leaves found',
                'data' => []
            ]);
        }

        $leaveData = $leaves->map(function ($leave) {
            return [
                'id' => $leave->id,
                'user_id' => $leave->user_id,
                'user_name' => $leave->user->name ?? 'Deleted User',
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'leave_type' => $leave->leave_type,
                'reason' => $leave->reason,
                'status' => $leave->status,
                'hours' => $leave->hours,
                'halfday_period' => $leave->halfday_period,
                'documents' => $leave->documents,
                'created_at' => $leave->created_at,
                'updated_at' => $leave->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'leaves data',
            'data' => $leaveData
        ]);
    }

    public function GetUsersAttendance(Request $request)
    {
        $current_user = auth()->user();

        // ROLE CHECK (JSON)
        if (!$current_user->hasAnyRole([1, 2, 3])) {
            return ApiResponse::error(
                'You are not authorized to access this data.',
                [],
                403
            );
        }

        if ($request->start_date && $request->end_date) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
        } else {
            $minDate = DB::table('leavespolicy')->min('start_date');

            $startDate = $minDate
                ? Carbon::parse($minDate)->startOfDay()
                : Carbon::now()->startOfMonth();

            $endDate = Carbon::now()->endOfDay();
        }

        $usersQuery = User::select('id', 'name', 'team_id')
            ->where(function ($q) {
                $q->whereRaw('NOT JSON_CONTAINS(role_id, ?)', [json_encode(1)])
                    ->whereRaw('NOT JSON_CONTAINS(role_id, ?)', [json_encode(2)]);
            })
            ->where('is_active', 1);

        if ($request->user_id) {
            $usersQuery->where('id', $request->user_id);
        }

        $users = $usersQuery->get();

        $teamIds = $users->pluck('team_id')
            ->flatMap(fn($teamIds) => $teamIds ?? [])
            ->unique()
            ->values();

        $teams = DB::table('teams')
            ->whereIn('id', $teamIds)
            ->pluck('name', 'id');

        $users = $users->map(function ($user) use ($teams) {
            $user->team_name = collect($user->team_id ?? [])
                ->map(fn($id) => $teams[$id] ?? null)
                ->filter()
                ->values()
                ->toArray();

            return $user;
        });

        // 🔽 everything below remains UNCHANGED 🔽

        $leaves = DB::table('leavespolicy')
            ->where('status', 'Approved')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })
            ->get()
            ->groupBy('user_id');

        $period = iterator_to_array(
            CarbonPeriod::create($startDate, $endDate)
        );

        $response = [];

        foreach ($users as $user) {

            $attendanceData = [];

            foreach ($period as $date) {
                $day = $date->format('Y-m-d');

                $attendanceData[$day] = [
                    'present' => 1,
                    'leave_type' => '',
                    'halfday_period' => '',
                    'hours' => '',
                    'reason' => '',
                    'status' => ''
                ];
            }

            if (isset($leaves[$user->id])) {
                foreach ($leaves[$user->id] as $leave) {
                    $leavePeriod = CarbonPeriod::create(
                        Carbon::parse($leave->start_date),
                        Carbon::parse($leave->end_date)
                    );

                    foreach ($leavePeriod as $day) {
                        if ($day->lt($startDate) || $day->gt($endDate)) {
                            continue;
                        }

                        $dayStr = $day->format('Y-m-d');

                        if ($leave->leave_type === 'Multiple Days Leave') {
                            $attendanceData[$dayStr]['present'] = 0;
                            $attendanceData[$dayStr]['leave_type'] = 'Full Leave';
                        }
                        if ($leave->leave_type === 'Full Leave') {
                            $attendanceData[$dayStr]['present'] = 0;
                            $attendanceData[$dayStr]['leave_type'] = 'Full Leave';
                        }

                        if ($leave->leave_type === 'Half Day') {
                            $attendanceData[$dayStr]['leave_type'] = 'Half Day';
                            $attendanceData[$dayStr]['halfday_period'] = $leave->halfday_period;
                        }

                        if ($leave->leave_type === 'Short Leave') {
                            $attendanceData[$dayStr]['leave_type'] = 'Short Leave';
                            $attendanceData[$dayStr]['hours'] = $leave->hours;
                        }

                        $attendanceData[$dayStr]['reason'] = $leave->reason;
                        $attendanceData[$dayStr]['status'] = $leave->status;
                    }
                }
            }

            $response[] = [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'team_name' => $user->team_name ?? '',
                'attendance_data' => $attendanceData
            ];
        }

        return ApiResponse::success(
            'User attendance fetched successfully',
            $response
        );
    }


}
