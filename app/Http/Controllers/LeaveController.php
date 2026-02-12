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
use App\Mail\LeaveAppliedMail;

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
            'is_wfh' => 'nullable|integer|in:0,1',

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
            'is_wfh' => (string) ($request->is_wfh ?? '0'),
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
        $SUPER_ADMIN = 1;
        $HR = 3;
        $BILLING_MANAGER = 4;
        $PM = 5;
        $TL = 6;
        $EMPLOYEE = 7;


        $ROLE_PRIORITY = [
            $SUPER_ADMIN,
            $HR,
            $BILLING_MANAGER,
            $PM,
            $TL,
            $EMPLOYEE
        ];


        $leaveUser = User::findOrFail($user_id);

        $userRoles = $leaveUser->role_id ?? [];
        $userRoles = is_array($userRoles) ? $userRoles : [$userRoles];

        $effectiveRole = null;

        foreach ($ROLE_PRIORITY as $role) {
            if (in_array($role, $userRoles)) {
                $effectiveRole = $role;
                break;
            }
        }


        $teamBasedRoles = [];
        $globalOnlyRoles = [];

        if ($effectiveRole === $EMPLOYEE) {
            $teamBasedRoles = [$TL, $PM];
            $globalOnlyRoles = [$HR, $BILLING_MANAGER, $SUPER_ADMIN];

        } elseif ($effectiveRole === $TL) {
            $teamBasedRoles = [$PM];
            $globalOnlyRoles = [$HR, $BILLING_MANAGER, $SUPER_ADMIN];

        } elseif ($effectiveRole === $PM) {
            $globalOnlyRoles = [$HR, $BILLING_MANAGER, $SUPER_ADMIN];

        } elseif ($effectiveRole === $BILLING_MANAGER) {
            $globalOnlyRoles = [$HR, $SUPER_ADMIN];

        } elseif ($effectiveRole === $HR) {
            $globalOnlyRoles = [$SUPER_ADMIN];
        }


        $globalUsers = User::where('is_active', 1)
            ->where(function ($q) use ($globalOnlyRoles) {
                foreach ($globalOnlyRoles as $roleId) {
                    $q->orWhereJsonContains('role_id', $roleId);
                }
            })
            ->get();

        $teamUsers = collect();

        if (!empty($teamBasedRoles)) {
            $teamIds = $leaveUser->team_id ?? [];
            $teamIds = is_array($teamIds) ? $teamIds : [$teamIds];

            $teamUsers = User::where('is_active', 1)
                ->where(function ($q) use ($teamBasedRoles) {
                    foreach ($teamBasedRoles as $roleId) {
                        $q->orWhereJsonContains('role_id', $roleId);
                    }
                })
                ->where(function ($q) use ($teamIds) {
                    foreach ($teamIds as $teamId) {
                        $q->orWhereJsonContains('team_id', $teamId);
                    }
                })
                ->get();
        }

        $mailUsers = $globalUsers
            ->merge($teamUsers)
            ->unique('id')
            ->values();
        foreach ($mailUsers as $user) {
            Mail::to($user->email)->queue(
                new LeaveAppliedMail($leave, $leaveUser)
            );
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
            ->where('is_active', '1');

        // If Team Lead → only employees
        if ($isTeamLead) {

            $teamMemberIds = User::whereJsonContains('role_id', 7)
                ->where('is_active', 1)
                ->where('tl_id', $user->id)
                ->whereNot('id', $user->id)
                ->pluck('id')
                ->toArray();


            $employeesQuery->whereIn('id', $teamMemberIds);
        } else if ($isManager) {
            $employeesQuery->where(function ($q) use ($team_id) {
                foreach ($team_id as $t) {
                    if ($t !== null) {
                        $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                    }
                }
            });
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
                    // Mail::to($tlUser->email)->queue($mail);
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
        // $managerRole = $current_user->role->name ?? 'Manager';
        $managerRole = $current_user->roles->pluck('name')->first() ?? 'Manager';

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
            Mail::to($user->email)->queue(
                new LeaveStatusUpdateMail($user, $leave, $managerName, $managerRole)
            );

        }

        return response()->json([
            'status' => true,
            'message' => "Leave status updated to {$finalStatus} and mail sent",
            'data' => $leave
        ]);
    }

    // public function getallLeavesbyUser()
    // {
    //     $currentUser = Auth::user();
    //     $teamIds = $currentUser->team_id ?? [];

    //     $leavesQuery = LeavePolicy::with('user:id,name,role_id,team_id')
    //         ->latest();

    //     if ($currentUser->hasRole(7)) {
    //         $leavesQuery->where('user_id', $currentUser->id);
    //     } elseif ($currentUser->hasRole(6)) {

    //         $teamMemberIds = User::whereJsonContains('role_id', 7)
    //             ->where('is_active', 1)
    //             ->where('tl_id', $currentUser->id)
    //             ->whereNot('id', $currentUser->id)
    //             ->pluck('id')
    //             ->toArray();
    //         $leavesQuery->whereIn('user_id', $teamMemberIds);

    //     } elseif ($currentUser->hasRole(5)) {
    //         $leavesQuery->whereHas('user', function ($q) use ($teamIds) {
    //             $q->where(function ($r) {
    //                 $r->whereRaw('JSON_CONTAINS(role_id, ?)', [json_encode(6)])
    //                     ->orWhereRaw('JSON_CONTAINS(role_id, ?)', [json_encode(7)]);
    //             })
    //                 ->where(function ($sub) use ($teamIds) {
    //                     foreach ($teamIds as $teamId) {
    //                         $sub->orWhereJsonContains('team_id', $teamId);
    //                     }
    //                 });
    //         });
    //     } elseif ($currentUser->hasAnyRole([1, 2, 3, 4])) {
    //         $leavesQuery->where('user_id', '!=', $currentUser->id);
    //     } else {
    //         $leavesQuery->whereHas('user', function ($q) use ($teamIds) {
    //             $q->whereRaw('JSON_CONTAINS(role_id, ?)', [json_encode(7)])
    //                 ->where(function ($sub) use ($teamIds) {
    //                     foreach ($teamIds as $teamId) {
    //                         $sub->orWhereJsonContains('team_id', $teamId);
    //                     }
    //                 });
    //         });
    //     }

    //     $leaves = $leavesQuery->get();

    //     if ($leaves->isEmpty()) {
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'No leaves found',
    //             'data' => []
    //         ]);
    //     }

    //     $leaveData = $leaves->map(function ($leave) {
    //         return [
    //             'id' => $leave->id,
    //             'user_id' => $leave->user_id,
    //             'user_name' => $leave->user->name ?? 'Deleted User',
    //             'start_date' => $leave->start_date,
    //             'end_date' => $leave->end_date,
    //             'leave_type' => $leave->leave_type,
    //             'reason' => $leave->reason,
    //             'status' => $leave->status,
    //             'hours' => $leave->hours,
    //             'halfday_period' => $leave->halfday_period,
    //             'documents' => $leave->documents,
    //             'created_at' => $leave->created_at,
    //             'updated_at' => $leave->updated_at,
    //         ];
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'leaves data',
    //         'data' => $leaveData
    //     ]);
    // }


    public function getallLeavesbyUser()
    {
        $currentUser = Auth::user();
        $teamIds = $currentUser->team_id ?? [];

        $leavesQuery = LeavePolicy::with('user:id,name,role_id,team_id')
            ->latest();
        $reporting_user = user::where('reporting_manager_id', $currentUser->id)->pluck('id')
            ->toArray();

        if ($currentUser->hasRole(7)) {
            $leavesQuery;
        } elseif ($currentUser->hasRole(6)) {

            $teamMemberIds = User::whereJsonContains('role_id', 7)
                ->where('is_active', 1)
                ->where('tl_id', $currentUser->id)
                ->whereNot('id', $currentUser->id)
                ->pluck('id')
                ->toArray();
            $leavesQuery->whereIn('user_id', $teamMemberIds);

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
            $leavesQuery->Orwhere(function ($q) use ($reporting_user) {
                $q->whereIn('user_id', $reporting_user);
            });

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

    // public function GetUsersAttendance(Request $request)
    // {
    //     $current_user = auth()->user();

    //     if (!$current_user->hasAnyRole([1, 2, 3])) {
    //         return ApiResponse::error(
    //             'You are not authorized to access this data.',
    //             [],
    //             403
    //         );
    //     }

    //     if ($request->start_date && $request->end_date) {
    //         $startDate = Carbon::parse($request->start_date)->startOfDay();
    //         $endDate = Carbon::parse($request->end_date)->endOfDay();
    //     } else {
    //         $minDate = DB::table('leavespolicy')->min('start_date');

    //         $startDate = $minDate
    //             ? Carbon::parse($minDate)->startOfDay()
    //             : Carbon::now()->startOfMonth();

    //         $endDate = Carbon::now()->endOfDay();
    //     }

    //     $usersQuery = User::select('id', 'name', 'team_id')
    //         ->where(function ($q) {
    //             $q->whereRaw('NOT JSON_CONTAINS(role_id, ?)', [json_encode(1)])
    //                 ->whereRaw('NOT JSON_CONTAINS(role_id, ?)', [json_encode(2)]);
    //         })
    //         ->where('is_active', 1);

    //     if ($request->user_id) {
    //         $usersQuery->where('id', $request->user_id);
    //     }

    //     $users = $usersQuery->get();

    //     $teamIds = $users->pluck('team_id')
    //         ->flatMap(fn($teamIds) => $teamIds ?? [])
    //         ->unique()
    //         ->values();

    //     $teams = DB::table('teams')
    //         ->whereIn('id', $teamIds)
    //         ->pluck('name', 'id');

    //     $users = $users->map(function ($user) use ($teams) {
    //         $user->team_name = collect($user->team_id ?? [])
    //             ->map(fn($id) => $teams[$id] ?? null)
    //             ->filter()
    //             ->values()
    //             ->toArray();
    //         return $user;
    //     });

    //     $leaves = DB::table('leavespolicy')
    //         ->where('status', 'Approved')
    //         ->where(function ($q) use ($startDate, $endDate) {
    //             $q->whereBetween('start_date', [$startDate, $endDate])
    //                 ->orWhereBetween('end_date', [$startDate, $endDate])
    //                 ->orWhere(function ($q) use ($startDate, $endDate) {
    //                     $q->where('start_date', '<=', $startDate)
    //                         ->where('end_date', '>=', $endDate);
    //                 });
    //         })
    //         ->get()
    //         ->groupBy('user_id');

    //     $performaSheets = DB::table('performa_sheets')
    //         ->select('user_id', 'data')
    //         ->whereIn('user_id', $users->pluck('id'))
    //         ->get()
    //         ->groupBy('user_id')
    //         ->map(function ($sheets) use ($startDate, $endDate) {

    //             return $sheets->map(function ($sheet) use ($startDate, $endDate) {

    //                 $firstDecode = json_decode($sheet->data, true);

    //                 if (!is_string($firstDecode)) {
    //                     return null;
    //                 }

    //                 $decoded = json_decode($firstDecode, true);

    //                 if (!$decoded || empty($decoded['date'])) {
    //                     return null;
    //                 }

    //                 $sheetDate = Carbon::parse($decoded['date'])->startOfDay();

    //                 if ($sheetDate->lt($startDate) || $sheetDate->gt($endDate)) {
    //                     return null;
    //                 }

    //                 return $sheetDate->format('Y-m-d');

    //             })
    //                 ->filter()
    //                 ->unique()
    //                 ->values();
    //         });

    //     // return $performaSheets;


    //     $period = iterator_to_array(
    //         CarbonPeriod::create($startDate, $endDate)
    //     );

    //     $response = [];


    //     foreach ($users as $user) {

    //         $attendanceData = [];
    //         $today = Carbon::today();
    //         $userSheetDates = $performaSheets[$user->id] ?? collect();

    //         foreach ($period as $date) {

    //             $dayStr = $date->format('Y-m-d');
    //             $isFuture = $date->gt($today);
    //             $isWeekend = $date->isSaturday() || $date->isSunday();

    //             $attendanceData[$dayStr] = [
    //                 'present' => 0,
    //                 'leave_type' => '',
    //                 'halfday_period' => '',
    //                 'hours' => '',
    //                 'reason' => '',
    //                 'status' => ''
    //             ];

    //             if ($userSheetDates->contains($dayStr)) {
    //                 $attendanceData[$dayStr]['present'] = 1;
    //                 continue;
    //             }

    //             if ($isFuture || $isWeekend) {
    //                 $attendanceData[$dayStr]['present'] = '';
    //             }
    //         }


    //         if (isset($leaves[$user->id])) {
    //             foreach ($leaves[$user->id] as $leave) {

    //                 $leavePeriod = CarbonPeriod::create(
    //                     Carbon::parse($leave->start_date),
    //                     Carbon::parse($leave->end_date)
    //                 );

    //                 foreach ($leavePeriod as $day) {

    //                     if ($day->lt($startDate) || $day->gt($endDate)) {
    //                         continue;
    //                     }

    //                     $dayStr = $day->format('Y-m-d');

    //                     if ($userSheetDates->contains($dayStr)) {
    //                         continue;
    //                     }

    //                     $attendanceData[$dayStr]['present'] = 0;

    //                     if (in_array($leave->leave_type, ['Full Leave', 'Multiple Days Leave'])) {
    //                         $attendanceData[$dayStr]['leave_type'] = 'Full Leave';
    //                     }

    //                     if ($leave->leave_type === 'Half Day') {
    //                         $attendanceData[$dayStr]['leave_type'] = 'Half Day';
    //                         $attendanceData[$dayStr]['halfday_period'] = $leave->halfday_period;
    //                     }

    //                     if ($leave->leave_type === 'Short Leave') {
    //                         $attendanceData[$dayStr]['leave_type'] = 'Short Leave';
    //                         $attendanceData[$dayStr]['hours'] = $leave->hours;
    //                     }

    //                     $attendanceData[$dayStr]['reason'] = $leave->reason;
    //                     $attendanceData[$dayStr]['status'] = $leave->status;
    //                 }
    //             }
    //         }

    //         $response[] = [
    //             'user_id' => $user->id,
    //             'user_name' => $user->name,
    //             'team_name' => $user->team_name ?? '',
    //             'attendance_data' => $attendanceData
    //         ];
    //     }

    //     return ApiResponse::success(
    //         'User attendance fetched successfully',
    //         $response
    //     );
    // }


    public function GetUsersAttendance(Request $request)
    {
        $current_user = auth()->user();

             if (!$current_user->hasAnyRoleIn([1, 2, 3, 4])) {
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

        $period = iterator_to_array(CarbonPeriod::create($startDate, $endDate));
        $today = Carbon::today();
        $response = [];

        foreach ($users as $user) {

            $attendanceData = [];

            foreach ($period as $date) {
                $day = $date->format('Y-m-d');
                $isWeekend = $date->isSaturday() || $date->isSunday();

                $attendanceData[$day] = [
                    'present' => ($isWeekend || $date->gt($today)) ? '' : 1,
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

                        if (
                            $leave->leave_type === 'Multiple Days Leave' ||
                            $leave->leave_type === 'Full Leave'
                        ) {
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

    // public function getLeavesForReportingManager(Request $request)
    // {
    //     $status = $request->status ?? null;
    //     $rm = $request->user();
    //     $startDate = $request->start_date ?? null;
    //     $endDate = $request->end_date ?? null;

    //     $buildTree = function ($managerId) use (&$buildTree) {

    //         $users = User::where('reporting_manager_id', $managerId)
    //             ->where('is_active', 1)
    //             ->select('id', 'name')
    //             ->get();

    //         $tree = [];

    //         foreach ($users as $user) {
    //             $tree[] = [
    //                 'user_id' => $user->id,
    //                 'user_name' => $user->name,
    //                 'children' => $buildTree($user->id),
    //             ];
    //         }

    //         return $tree;
    //     };

    //     $flattenIds = function ($tree) use (&$flattenIds) {

    //         $ids = [];

    //         foreach ($tree as $node) {
    //             $ids[] = $node['user_id'];

    //             if (!empty($node['children'])) {
    //                 $ids = array_merge($ids, $flattenIds($node['children']));
    //             }
    //         }

    //         return $ids;
    //     };

    //     $attachLeaves = function ($node, $leaves) use (&$attachLeaves) {

    //         $userLeaves = [];

    //         if (isset($leaves[$node['user_id']])) {
    //             foreach ($leaves[$node['user_id']] as $leave) {
    //                 $userLeaves[] = [
    //                     'id' => $leave->id,
    //                     'start_date' => $leave->start_date,
    //                     'end_date' => $leave->end_date,
    //                     'leave_type' => $leave->leave_type ?? null,
    //                     'reason' => $leave->reason ?? null,
    //                     'status' => $leave->status,
    //                     'created_at' => optional($leave->created_at)->format('Y-m-d H:i:s'),
    //                     'updated_at' => optional($leave->updated_at)->format('Y-m-d H:i:s'),
    //                 ];
    //             }
    //         }

    //         $children = [];

    //         if (!empty($node['children'])) {
    //             foreach ($node['children'] as $child) {
    //                 $children[] = $attachLeaves($child, $leaves);
    //             }
    //         }

    //         return [
    //             'user_id' => $node['user_id'],
    //             'user_name' => $node['user_name'],
    //             'leaves' => $userLeaves,
    //             'children' => $children
    //         ];
    //     };

    //     $teamTree = $buildTree($rm->id);

    //     $subordinateIds = $flattenIds($teamTree);

    //     $leaveQuery = LeavePolicy::whereIn('user_id', $subordinateIds);

    //     if ($status) {
    //         $leaveQuery->where('status', $status);
    //     }
    //     if ($startDate && $endDate) {
    //         $leaveQuery->where(function ($q) use ($startDate, $endDate) {
    //             $q->where('start_date', '<=', $endDate)
    //                 ->where('end_date', '>=', $startDate);
    //         });
    //     }

    //     $leaves = $leaveQuery->get()->groupBy('user_id');

    //     $finalData = $attachLeaves([
    //         'user_id' => $rm->id,
    //         'user_name' => $rm->name,
    //         'children' => $teamTree
    //     ], $leaves);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'RM based leave data fetched successfully',
    //         'data' => $finalData
    //     ]);
    // }


    // public function getLeavesForReportingManager(Request $request)
    // {
    //     $status = $request->status ?? null;
    //     $startDate = $request->start_date ?? null;
    //     $endDate = $request->end_date ?? null;
    //     $rm = $request->user();

    //     $rmRoles = is_array($rm->role_id)
    //         ? $rm->role_id
    //         : [(int) $rm->role_id];

    //     if (!empty(array_intersect($rmRoles, [1, 2, 3, 4]))) {

    //         $subordinateIds = User::where('is_active', 1)
    //             ->where('id', '!=', $rm->id)
    //             ->where(function ($q) {
    //                 foreach ([1, 2, 3, 4] as $role) {
    //                     $q->whereJsonDoesntContain('role_id', $role);
    //                 }
    //             })
    //             ->pluck('id')
    //             ->toArray();

    //     } else {

    //         $buildTree = function ($managerId) use (&$buildTree) {
    //             return User::where('reporting_manager_id', $managerId)
    //                 ->where('is_active', 1)
    //                 ->select('id')
    //                 ->get()
    //                 ->map(function ($user) use ($buildTree) {
    //                     return [
    //                         'user_id' => $user->id,
    //                         'children' => $buildTree($user->id),
    //                     ];
    //                 })
    //                 ->toArray();
    //         };

    //         $flattenIds = function ($tree) use (&$flattenIds) {
    //             $ids = [];
    //             foreach ($tree as $node) {
    //                 $ids[] = $node['user_id'];
    //                 if (!empty($node['children'])) {
    //                     $ids = array_merge($ids, $flattenIds($node['children']));
    //                 }
    //             }
    //             return $ids;
    //         };

    //         $teamTree = $buildTree($rm->id);
    //         $subordinateIds = $flattenIds($teamTree);
    //     }

    //     $leaveQuery = LeavePolicy::whereIn('user_id', $subordinateIds);

    //     if ($status) {
    //         $leaveQuery->where('status', $status);
    //     }

    //     if ($startDate && $endDate) {
    //         $leaveQuery->where(function ($q) use ($startDate, $endDate) {
    //             $q->where('start_date', '<=', $endDate)
    //                 ->where('end_date', '>=', $startDate);
    //         });
    //     }

    //     $leaves = $leaveQuery->get();

    //     $users = User::whereIn('id', $subordinateIds)
    //         ->select('id', 'name')
    //         ->get()
    //         ->keyBy('id');

    //     $mergedLeaves = [];

    //     foreach ($leaves as $leave) {
    //         $mergedLeaves[] = [
    //             'user_id' => $leave->user_id,
    //             'user_name' => $users[$leave->user_id]->name ?? null,
    //             'leave_id' => $leave->id,
    //             'start_date' => $leave->start_date,
    //             'end_date' => $leave->end_date,
    //             'leave_type' => $leave->leave_type ?? null,
    //             'reason' => $leave->reason ?? null,
    //             'status' => $leave->status,
    //             'created_at' => optional($leave->created_at)->format('Y-m-d H:i:s'),
    //             'updated_at' => optional($leave->updated_at)->format('Y-m-d H:i:s'),
    //         ];
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'RM based leave data fetched successfully',
    //         'data' => [
    //             'rm_id' => $rm->id,
    //             'rm_name' => $rm->name,
    //             'leaves' => $mergedLeaves
    //         ]
    //     ]);
    // }


    public function getLeavesForReportingManager(Request $request)
    {
        $status = $request->status;
        $startDate = $request->start_date;
        $endDate = $request->end_date;
        $rm = $request->user();

        $rmRoles = is_array($rm->role_id)
            ? array_map('intval', $rm->role_id)
            : [(int) $rm->role_id];

        $subordinateIds = [];

        $roleExclusionMap = [
            1 => [1, 2],
            2 => [1, 2],
            3 => [1, 2, 3],
            4 => [1, 2, 3, 4],
        ];

        $matchedRoles = array_intersect(array_keys($roleExclusionMap), $rmRoles);

        if (!empty($matchedRoles)) {

            $excludeRoles = [];

            foreach ($matchedRoles as $role) {
                $excludeRoles = array_merge($excludeRoles, $roleExclusionMap[$role]);
            }

            $excludeRoles = array_unique($excludeRoles);

            $subordinateIds = User::where('is_active', 1)
                ->where('id', '!=', $rm->id)
                ->where(function ($q) use ($excludeRoles) {
                    foreach ($excludeRoles as $role) {
                        $q->whereJsonDoesntContain('role_id', $role);
                    }
                })
                ->pluck('id')
                ->toArray();

        } else {

            $buildTree = function ($managerId) use (&$buildTree) {
                return User::where('reporting_manager_id', $managerId)
                    ->where('is_active', 1)
                    ->pluck('id')
                    ->map(fn($id) => [
                        'user_id' => $id,
                        'children' => $buildTree($id),
                    ])
                    ->toArray();
            };

            $flattenIds = function ($tree) use (&$flattenIds) {
                $ids = [];
                foreach ($tree as $node) {
                    $ids[] = $node['user_id'];
                    if (!empty($node['children'])) {
                        $ids = array_merge($ids, $flattenIds($node['children']));
                    }
                }
                return $ids;
            };

            $teamTree = $buildTree($rm->id);
            $subordinateIds = $flattenIds($teamTree);
        }

        if (empty($subordinateIds)) {
            return response()->json([
                'success' => true,
                'message' => 'No leave records found',
                'data' => [
                    'rm_id' => $rm->id,
                    'rm_name' => $rm->name,
                    'leaves' => [],
                ],
            ]);
        }

        $leaveQuery = LeavePolicy::whereIn('user_id', $subordinateIds);

        if ($status) {
            $leaveQuery->where('status', $status);
        }

        if ($startDate && $endDate) {
            $leaveQuery->where(function ($q) use ($startDate, $endDate) {
                $q->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate);
            });
        }

        $leaves = $leaveQuery->get();

        $users = User::whereIn('id', $subordinateIds)
            ->select('id', 'name')
            ->get()
            ->keyBy('id');

        $mergedLeaves = $leaves->map(function ($leave) use ($users) {
            return [
                'user_id' => $leave->user_id,
                'user_name' => $users[$leave->user_id]->name ?? null,
                'leave_id' => $leave->id,
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'leave_type' => $leave->leave_type,
                'reason' => $leave->reason,
                'status' => $leave->status,
                'created_at' => optional($leave->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => optional($leave->updated_at)->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'RM based leave data fetched successfully',
            'data' => [
                'rm_id' => $rm->id,
                'rm_name' => $rm->name,
                'leaves' => $mergedLeaves,
            ],
        ]);
    }


}
