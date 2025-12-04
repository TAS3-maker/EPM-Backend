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
		$user = auth()->user(); 
		$request->validate([
			'start_date' => 'required|date|after_or_equal:today',
			'end_date' => 'nullable|date|after_or_equal:start_date',
			'leave_type' => 'required|in:Full Leave,Short Leave,Half Day,Multiple Days Leave',
			'reason' => 'required',
			'status' => 'in:Pending,Approved,Rejected',
			'hours' => [
        'nullable',
        function ($attribute, $value, $fail) use ($request) {
            if ($request->leave_type === 'Short Leave') {
                // Regex: Example format "4PM to 8PM"
                if (!preg_match('/^(1[0-2]|0?[1-9])(AM|PM)\s+to\s+(1[0-2]|0?[1-9])(AM|PM)$/i', $value)) {
                    $fail('Hours must be in format like "4PM to 8PM".');
                }
            }
        }
    ],
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
		} elseif ($request->leave_type === 'Multiple Days Leave' && isset($request->end_date)) {
			$endDate = $request->end_date; 
		} else {
			return response()->json([
				'success' => false,
				'message' => "End date is required for Multiple Days Leave"
			], 400);
		}
		$hours = ($request->leave_type === 'Short Leave') ? ($request->hours ?? null) : null;
		$halfdayPeriod = ($request->leave_type === 'Half Day') ? strtolower($request->halfday_period) : null;
		$leave = LeavePolicy::create([
				'user_id' => $user->id, 
				'start_date' => $request->start_date,
				'end_date' => $endDate, 
				'leave_type' => $request->leave_type,
				'reason' => $request->reason,
				'status' => $request->status ?? 'Pending',
				'hours' => $hours,
                'halfday_period' => $halfdayPeriod,
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
            'url' => !empty($filename) ? asset('storage/' . $filename):'',
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
                'created_at' => $leave->created_at,
                'updated_at' => $leave->updated_at
            ];
        });
        return response()->json([
            'success' => true,
            'data' => $leaveData
        ]);
    }

    public function getLeavesByemploye()
    {
        $user = auth()->user();
        
        // code commented to show only current user's leaves
        // if ($user->role_id == 7) {
            $leaves = LeavePolicy::with('user:id,name')
                ->where('user_id', $user->id)
                ->latest()
                ->get();
        // } else {
        //     $leaves = LeavePolicy::with('user:id,name')
        //         ->latest()
        //         ->get();
        // }

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
        $managerInfo = [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role_id == 6 ? 'Team Lead' : 'Manager',
            'team_id' => $user->team_id,
        ];

        if ($user->role_id == 6) {
            $employees = User::where('id', '!=', $user->id)
                ->where(function ($q) use ($team_id) {
                        foreach ($team_id as $t) {
                            if ($t !== null) {
                                $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                            }
                        }
                })
                ->where('role_id', 7)         
                ->get();
        } else {
            $employees = User::where('id', '!=', $user->id)
                ->where(function ($q) use ($team_id) {
                        foreach ($team_id as $t) {
                            if ($t !== null) {
                                $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                            }
                        }
                })
                ->get();
        }

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

                $employee = User::find($employeeId);
                $tlUser = null;

                if ($employee && isset($employee->tl_id)) {
                    $tlUser = User::find($employee->tl_id);
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

        $user = User::find($leave->user_id);

        if ($user && $user->email) {
            Mail::to($user->email)->send(
                new LeaveStatusUpdateMail($user, $leave, $managerName, $managerRole)
            );
        }

        return response()->json([
            'status' => true,
            'message' => "Leave status updated to {$finalStatus} and mail sent",
            'data' => $leave
        ]);
    }

}
