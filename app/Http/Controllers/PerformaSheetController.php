<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Project;
use App\Models\User;
use App\Models\Task;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\ProjectResource;
use App\Mail\EmployeePerformaSheet;
use App\Models\ApplicationPerforma;
use App\Models\PerformaSheet;
use App\Models\Role;
use App\Models\TagsActivity;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Models\LeavePolicy;
use App\Models\ProjectMaster;
use App\Models\Team;
use App\Services\ActivityService;


class PerformaSheetController extends Controller
{
    public function addPerformaSheets(Request $request)
    {
        $submitting_user = auth()->user();
        $submitting_user_name = $submitting_user->name;
        $submitting_user_employee_id = $submitting_user->employee_id;
        try {
            $validatedData = $request->validate([
                'data' => 'required|array',
                'data.*.project_id' => [
                    'required',
                    Rule::exists('project_relations', 'project_id')->where(function ($query) use ($submitting_user) {
                        $query->whereRaw(
                            'JSON_CONTAINS(assignees, ?, "$")',
                            [json_encode((int) $submitting_user->id)]
                        );
                    })
                ],
                'data.*.date' => 'required|date_format:Y-m-d',
                'data.*.time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
                'data.*.task_id' => 'nullable|integer',
                'data.*.work_type' => 'required|string|max:255',
                'data.*.narration' => 'nullable|string',
                'data.*.is_tracking' => 'required|in:yes,no',
                'data.*.tracking_mode' => 'nullable|in:all,partial',
                'data.*.tracked_hours' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
                // 'data.*.offline_hours' => 'nullable',
                // 'data.*.status' => 'nullable',
                'data.*.is_fillable' => 'required|boolean',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed!',
                'errors' => $e->errors()
            ], 422);
        }

        foreach ($validatedData['data'] as $record) {
            $project = ProjectMaster::with('tagActivityRelated:id,name')->find($record['project_id']);
            $projectName = $project ? $project->project_name : "Unknown Project";

            if (empty($project->tagActivityRelated->name)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project Activity Type is not assigned to Project.'
                ], 422);
            }
            $record['project_type'] = 'Fixed';
            $record['project_type_status'] = 'Offline';
            $record['activity_type'] = $project->tagActivityRelated?->name;
            if (
                isset($project->tagActivityRelated->name) &&
                (strtolower($project->tagActivityRelated->name) === 'non billable' ||
                    strtolower($project->tagActivityRelated->name) === 'non-billable')
            ) {
                $record['activity_type'] = 'Billable';
            }
            if ($project->tagActivityRelated->id == 18) {
                $record['project_type'] = 'No Work';
            }

            if ($record['is_tracking'] === 'yes' && $project && $project->project_tracking) {
                if ($record['tracking_mode'] === 'all') {
                    $record['tracked_hours'] = $record['time'];
                    $record['offline_hours'] = '00:00';

                } else if ($record['tracking_mode'] === 'partial') {
                    if (empty($record['tracked_hours'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Tracked hours are required when tracking mode is partial.'
                        ], 422);
                    }
                    if ((int) $project->offline_hours !== 1) {
                        return response()->json([
                            'success' => false,
                            'message' => "Offline hours are not allowed for the project '{$project->project_name}'."
                        ], 422);
                    }

                    $totalMinutes = $this->timeToMinutes($record['time']);
                    $trackedMinutes = $this->timeToMinutes($record['tracked_hours']);

                    if ($trackedMinutes > $totalMinutes) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Tracked hours cannot be greater than total time.'
                        ], 422);
                    }
                    $offlineMinutes = $totalMinutes - $trackedMinutes;
                    $record['offline_hours'] = $this->minutesToTime($offlineMinutes);
                }
            } else {
                $record['is_tracking'] = 'no';
                $record['tracking_mode'] = '';
                $record['tracked_hours'] = '00:00';
                $record['offline_hours'] = '00:00';
            }

            if ($project && $project->project_tracking) {
                $record['activity_type'] = 'Billable';
                $record['project_type'] = 'Hourly';
                $record['project_type_status'] = 'Online';
            }

            /* if (
                !isset($record['activity_type']) ||
                strtolower($record['activity_type']) === 'non billable' ||
                strtolower($record['activity_type']) === 'non-billable'
            ) {
                $record['activity_type'] = 'Billable';
            } 

            if ($project && $project->billing_type === 'hourly') {
                $record['activity_type'] = 'Billable';
            }
            */
            // Check if project has tasks
            $tasks = Task::where('project_id', $record['project_id'])->get();

            if ($tasks->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "No tasks found for project '{$projectName}'. Please create at least one task to proceed."
                ], 400);
            }

            // Check for valid (active) task
            $validStatusTask = $tasks->first(function ($task) {
                return in_array(strtolower($task->status), ['to do', 'in progress']);
            });

            if (!$validStatusTask) {
                return response()->json([
                    'success' => false,
                    'message' => "All tasks for project '{$projectName}' are either completed or not started. Please update task status to 'To do' or 'In progress'."
                ], 400);
            }

            $isFillable = (bool) ($record['is_fillable'] ?? false);
            if (isset($record['status']) && strtolower($record['status']) == 'standup') {
                $status = 'standup';
            } else {
                $status = $isFillable ? 'standup' : 'backdated';
            }
            // Create Performa Sheet
            $insertedSheet = PerformaSheet::create([
                'user_id' => $submitting_user->id,
                'status' => $status,
                'data' => json_encode($record)
            ]);

            $inserted[] = $insertedSheet;
            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Performa Sheets added by ' . auth()->user()->name,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => count($inserted) . ' Performa Sheets added successfully',
            'data' => $inserted
        ]);
    }
    private function timeToMinutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return ((int) $h * 60) + (int) $m;
    }

    private function minutesToTime(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d', $hours, $mins);
    }
    public function submitForApproval(Request $request)
    {
        $user = auth()->user();
        try {
            $validatedData = $request->validate([
                'data' => 'required|array|min:1',
                'data.*.id' => [
                    'required',
                    Rule::exists('performa_sheets', 'id')->where('user_id', $user->id),
                ],
                'data.*.date' => 'required|date_format:Y-m-d',
                'data.*.is_fillable' => 'nullable|boolean',
                'data.*.status' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed!',
                'errors' => $e->errors()
            ], 422);
        }

        $updatedCount = 0;
        $inserted = [];
        $sheetsWithDetails = [];

        foreach ($validatedData['data'] as $record) {

            $sheet = PerformaSheet::where('id', $record['id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$sheet) {
                continue;
            }

            /*$record['is_fillable'] = 1;
             $isFillable = (bool) ($record['is_fillable'] ?? false);
            $status = $isFillable ? 'pending' : 'draft'; */

            $data = json_decode($sheet->data, true) ?? [];
            $data['is_fillable'] = 1;

            $sheet->update([
                'data' => json_encode($data),
                'status' => 'pending',
            ]);
            $sheet_data = json_decode($sheet->data);
            $updatedCount++;
            $project = ProjectMaster::select('project_name')->find($sheet_data->project_id);
            //sheet details for mail/report
            $sheetsWithDetails[] = [
                'submitting_user' => $user->name,
                'project_name' => $project->project_name,
                'task_id' => $sheet_data->task_id,
                'date' => $sheet_data->date,
                'time' => $sheet_data->time,
                'work_type' => $sheet_data->work_type,
                'activity_type' => $sheet_data->activity_type,
                'narration' => $sheet_data->narration,
                'project_type' => $sheet_data->project_type,
                'project_type_status' => $sheet_data->project_type_status,
            ];
            if ($sheet->is_tracking) {
                $sheetsWithDetails['is_tracking'] = $sheet_data->is_tracking;
                $sheetsWithDetails['tracking_mode'] = $sheet_data->tracking_mode;
                $sheetsWithDetails['tracked_hours'] = $sheet_data->tracked_hours;
            }
            ActivityService::log([
                'project_id' => $sheet_data->project_id,
                'type' => 'activity',
                'description' => 'Performa Sheets submitted for approval by ' . $user->name,
            ]);
        }

        // Get users with higher roles (Super Admin / Billing Manager)
        /* $users = User::whereHas('role', function ($query) {
            $query->whereIn('name', ['Super Admin', 'Billing Manager']);
        })->get();

        $submitting_date_for_mail = $record['date'];

        foreach ($users as $user) {
            // Mail::to($user->email)->send(new EmployeePerformaSheet($sheetsWithDetails, $user,$submitting_user_name, $submitting_user_employee_id, $submitting_date_for_mail));
        } */
        //  $roleIds = Role::whereIn('name', [
        //     'Super Admin',
        //     'Billing Manager'
        // ])->pluck('id')->toArray();
        // $users = User::where(function ($q) use ($roleIds) {
        //     foreach ($roleIds as $roleId) {
        //         $q->orWhereJsonContains('role_id', $roleId);
        //     }
        // })
        //     ->where('is_active', 1)
        //     ->get();
        // $submitting_date_for_mail = $record['date'];

        // foreach ($users as $user) {
        //     Mail::to($user->email)->send(
        //         new EmployeePerformaSheet(
        //            $sheetsWithDetails, $user,$user->name, $user->employee_id, $submitting_date_for_mail
        //         )
        //     );
        // }

        $submitting_user_name = $user->name;
        $submitting_employee_id = $user->employee_id;
        $tl = User::where('id', $user->tl_id)
                ->where('is_active', 1)
                ->first();

        $teamIds = $user->team_id ?? [];
                
        $projectManagers = User::where('is_active', 1)
                    ->whereJsonContains('role_id', 5)
                    ->where(function ($q) use ($teamIds) {
                        foreach ($teamIds as $teamId) {
                            $q->orWhereJsonContains('team_id', $teamId);
                        }
                    })
                    ->get();
                    
                $roleIds = Role::whereIn('name', [
                    'superadmin',
                    'Billing Manager'
                ])->pluck('id')->toArray();

                $approvers = User::where(function ($q) use ($roleIds) {
                    foreach ($roleIds as $roleId) {
                        $q->orWhereJsonContains('role_id', $roleId);
                    }
                })
                    ->where('is_active', 1)
                    ->get();
                    
            $users = collect($approvers)
            ->merge($projectManagers)
            ->when($tl, fn ($c) => $c->push($tl))
            ->unique('id')
            ->values();

        $submitting_date_for_mail = $record['date'];

        foreach ($users as $user) {
            Mail::to($user->email)->send(
                new EmployeePerformaSheet(
                   $sheetsWithDetails, $user,$submitting_user_name, $submitting_employee_id, $submitting_date_for_mail
                )
            );
        }
        
        return response()->json([
            'success' => true,
            'message' => $updatedCount . ' Performa Sheets submitted for approval successfully',
        ]);
    }


    public function getUserPerformaSheets()
    {
        $user = auth()->user();
        $sheets = PerformaSheet::with('user:id,name')
            ->where('user_id', $user->id)->whereIn('status', ['pending', 'approved', 'rejected','backdated'])
            ->orderBy('created_at', 'desc')
            ->get();

        $structuredData = [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'sheets' => []
        ];

        foreach ($sheets as $sheet) {
            $dataArray = json_decode($sheet->data, true);
            if (!is_array($dataArray)) {
                continue;
            }

            $projectId = $dataArray['project_id'] ?? null;
            $project = $projectId ? ProjectMaster::with('client')->find($projectId) : null;
            $projectName = $project->project_name ?? 'No Project Found';
            $clientName = $project->client->client_name ?? 'No Client Found';
            $deadline = $project->deadline ?? 'No Deadline Set';

            unset($dataArray['user_id'], $dataArray['user_name']);

            $dataArray['id'] = $sheet->id;
            $dataArray['project_name'] = $projectName;
            $dataArray['client_name'] = $clientName;
            $dataArray['deadline'] = $deadline;
            $dataArray['status'] = $sheet->status ?? 'pending';

            $structuredData['sheets'][] = $dataArray;
        }

        if (empty($structuredData['sheets'])) {
            unset($structuredData['sheets']);
        }
        return response()->json([
            'success' => true,
            'message' => 'Performa Sheets fetched successfully',
            'data' => $structuredData
        ]);
    }


    public function getAllPerformaSheets(Request $request)
    {
        $user = $request->user();
        $team_id = $user->team_id ?? [];
        $status = $request->status ?? null;

        $baseQuery = PerformaSheet::with('user:id,name');
        if ($user->hasRole(7)) {
            $baseQuery->where('user_id', $user->id);
        } elseif ($user->hasAnyRole([1, 2, 3, 4])) {

            $teamMemberIds = User::whereJsonContains('role_id', 7)
                ->where('is_active', 1)
                ->pluck('id')
                ->toArray();

            $baseQuery->whereIn('user_id', $teamMemberIds);
        } elseif (!empty($team_id)) {

            $teamMemberIds = User::whereJsonContains('role_id', 7)
                ->where('is_active', 1)
                ->where(function ($q) use ($team_id) {
                    foreach ($team_id as $t) {
                        $q->orWhereRaw(
                            'JSON_CONTAINS(team_id, ?)',
                            [json_encode($t)]
                        );
                    }
                })
                ->pluck('id')
                ->toArray();

            $baseQuery->whereIn('user_id', $teamMemberIds);
        }
        if (!empty($status)) {
            $baseQuery->where('status', $status);
        } else {
            $baseQuery->whereIn('status', ['approved', 'rejected']);
        }

        $sheets = $baseQuery->get();
        $structuredData = [];

        foreach ($sheets as $sheet) {

            $dataArray = json_decode($sheet->data, true);
            if (!is_array($dataArray)) {
                continue;
            }

            $projectId = $dataArray['project_id'] ?? null;
            $project = $projectId
                ? ProjectMaster::with('client')->find($projectId)
                : null;

            $dataArray['project_name'] = $project->project_name ?? 'No Project Found';
            $dataArray['client_name'] = $project->client->client_name ?? 'No Client Found';
            $dataArray['deadline'] = $project->deadline ?? 'No Deadline Set';
            $dataArray['status'] = $sheet->status ?? 'pending';
            $dataArray['id'] = $sheet->id;
            $dataArray['created_at'] = optional($sheet->created_at)->format('Y-m-d H:i:s');
            $dataArray['updated_at'] = optional($sheet->updated_at)->format('Y-m-d H:i:s');

            unset($dataArray['user_id'], $dataArray['user_name']);

            if (!isset($structuredData[$sheet->user_id])) {
                $structuredData[$sheet->user_id] = [
                    'user_id' => $sheet->user_id,
                    'user_name' => $sheet->user->name ?? 'No User Found',
                    'sheets' => []
                ];
            }

            $structuredData[$sheet->user_id]['sheets'][] = $dataArray;
        }

        return response()->json([
            'success' => true,
            'message' => 'All Performa Sheets fetched successfully',
            'data' => array_values($structuredData)
        ]);
    }


    public function getApprovalPerformaSheets(Request $request)
    {

        $request->validate([
            'ids' => 'required|array',
            'status' => 'required|string'
        ]);

        $responses = [];

        foreach ($request->ids as $id) {
            $performa = PerformaSheet::find($id);

            if (!$performa) {
                $responses[] = [
                    'id' => $id,
                    'success' => false,
                    'message' => 'Performa not found'
                ];
                continue;
            }

            $originalData = json_decode($performa->data, true);
            $projectId = $originalData['project_id'];
            $activityType = $originalData['activity_type'];

            list($hours, $minutes) = explode(':', $originalData['time']);
            $timeInHours = (int) $hours + ((int) $minutes / 60);

            $project = Project::find($projectId);
            if (!$project) {
                $responses[] = [
                    'id' => $id,
                    'success' => false,
                    'message' => 'Project not found'
                ];
                continue;
            }

            $isHourly = $project->billing_type === 'hourly';

            if ($isHourly) {
                $billableHoursValue = $timeInHours;
                $extraHours = 0;
            } else {
                $remainingHours = $project->remaining_hours ?? 0;
                $billableHoursValue = min($timeInHours, $remainingHours);
                $extraHours = max(0, $timeInHours - $remainingHours);
            }

            // \Log::info('==== Debug Performa Start ====');
            // \Log::info('Project ID: ' . $projectId);
            // \Log::info('Billing Type: ' . $project->billing_type);
            // \Log::info('timeInHours: ' . $timeInHours);
            // \Log::info('billableHoursValue: ' . $billableHoursValue);
            // \Log::info('extraHours: ' . $extraHours);

            $billableHours = floor($billableHoursValue);
            $billableMinutes = round(($billableHoursValue - $billableHours) * 60);
            $billableTimeFormatted = sprintf('%02d:%02d', $billableHours, $billableMinutes);

            $nonBillableHours = floor($extraHours);
            $nonBillableMinutes = round(($extraHours - $nonBillableHours) * 60);
            $nonBillableTimeFormatted = sprintf('%02d:%02d', $nonBillableHours, $nonBillableMinutes);

            // \Log::info('billableTimeFormatted: ' . $billableTimeFormatted);
            // \Log::info('nonBillableTimeFormatted: ' . $nonBillableTimeFormatted);
            // \Log::info('==== Debug Performa End ====');

            if ($request->status === 'approved') {
                if ($billableHoursValue > 0) {
                    $billableData = $originalData;
                    $billableData['time'] = $billableTimeFormatted;
                    // $billableData['activity_type'] = "Billable";
                    $billableData['message'] = $isHourly ? "Billable - hourly project" : "Billable - within limit";

                    $performa->data = json_encode($billableData);
                    $performa->status = 'approved';
                    $performa->save();

                    if (!$isHourly) {
                        $project->remaining_hours = max(0, $project->remaining_hours - $billableHoursValue);
                    }
                }

                // Only create non-billable if NOT hourly and extra hours exist
                if (!$isHourly && $extraHours > 0) {
                    $nonBillableData = $originalData;
                    $nonBillableData['time'] = $nonBillableTimeFormatted;
                    // $nonBillableData['activity_type'] = "Billable";
                    $nonBillableData['message'] = "Billable - Extra hours approved";

                    if ($originalData['activity_type'] === 'Non Billable') {
                        $performa->data = json_encode($nonBillableData);
                        $performa->status = 'approved';
                        $performa->save();
                    } else {
                        $newPerforma = new PerformaSheet();
                        $newPerforma->user_id = $performa->user_id;
                        $newPerforma->status = 'approved';
                        $newPerforma->data = json_encode($nonBillableData);
                        $newPerforma->save();
                    }
                }

                $project->total_working_hours += $timeInHours;
                $project->save();
            } else {
                $performa->status = $request->status;
                $performa->save();
            }

            $responses[] = [
                'id' => $id,
                'success' => true,
                'message' => 'Performa updated successfully',
                'final_total_working_hours' => $project->total_working_hours,
                'remaining_hours' => $project->remaining_hours ?? null,
                'extra_hours' => $extraHours ?? null
            ];
        }

        return response()->json([
            'results' => $responses
        ]);
    }


    public function SinkPerformaAPI(Request $request)
    {
        $request->validate([
            'project_id' => 'required|integer',
        ]);

        $projectId = $request->project_id;

        // 1. Get all task statuses
        $statuses = DB::table('tasks')
            ->where('project_id', $projectId)
            ->pluck('status');

        // 2. Check if all tasks are "Completed"
        $allTasksCompleted = !$statuses->contains(function ($status) {
            return strtolower($status) !== 'completed';
        });

        if (!$allTasksCompleted) {
            return response()->json([
                'success' => true,
                'message' => 'All tasks are not completed',
                'all_completed' => false,
                'remaining_hours' => 0
            ]);
        }

        // 3. Get project details
        $project = DB::table('projects')->where('id', $projectId)->first();

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }

        $totalHours = (float) $project->total_hours;
        $workingHours = (float) $project->total_working_hours;
        $remainingHours = max(0, $totalHours - $workingHours);

        // 4. Get all Non Billable entries for this project from performa_sheets
        $entries = PerformaSheet::where('status', 'approved')->get()->filter(function ($entry) use ($projectId) {
            $data = json_decode($entry->data, true);
            return isset($data['project_id'], $data['activity_type']) &&
                $data['project_id'] == $projectId &&
                $data['activity_type'] == 'Non Billable';
        });

        $converted = [];
        $remaining = $remainingHours;

        foreach ($entries as $entry) {
            $data = json_decode($entry->data, true);
            $entryHours = timeToFloat($data['time']); // helper to convert HH:MM to float
            if ($remaining <= 0)
                break;

            if ($entryHours <= $remaining) {
                // Fully convert to Billable
                // $data['activity_type'] = 'Billable';
                $data['message'] = 'Converted from Non Billable to Billable via Sync';
                $entry->data = json_encode($data);
                $entry->save();

                $converted[] = $entry;
                $remaining -= $entryHours;
                $workingHours += $entryHours;
            } else {
                // Partially convert: update existing with Billable, create new with leftover Non Billable
                $billableTime = floatToTime($remaining);
                $nonBillableTime = floatToTime($entryHours - $remaining);

                // Update current entry
                $data['time'] = $billableTime;
                // $data['activity_type'] = 'Billable';
                $data['message'] = 'Partially converted to Billable via Sync';
                $entry->data = json_encode($data);
                $entry->save();

                $workingHours += $remaining;

                // Create new Non Billable entry with leftover
                //code changed to billable
                $newData = $data;
                // $newData['activity_type'] = 'Billable';
                $newData['time'] = $nonBillableTime;
                $newData['message'] = 'Remaining Billable after partial conversion';

                $newEntry = PerformaSheet::create([
                    'user_id' => $entry->user_id,
                    'data' => json_encode($newData),
                    'status' => 'approved',
                ]);

                $converted[] = $entry;
                $converted[] = $newEntry;

                $remaining = 0;
            }
        }

        // Update project total_working_hours
        DB::table('projects')->where('id', $projectId)->update([
            'total_working_hours' => $workingHours
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Billable entries converted based on remaining hours',
            'converted' => $converted,
            'updated_total_working_hours' => $workingHours,
            'remaining_after_conversion' => max(0, $totalHours - $workingHours),
        ]);
    }
    public function SinkPerformaAPIMaster(Request $request)
    {
        $request->validate([
            'project_id' => 'required|integer',
        ]);

        $projectId = $request->project_id;

        // 1. Get all task statuses
        $statuses = DB::table('tasks')
            ->where('project_id', $projectId)
            ->pluck('status');

        // 2. Check if all tasks are "Completed"
        $allTasksCompleted = !$statuses->contains(function ($status) {
            return strtolower($status) !== 'completed';
        });

        if (!$allTasksCompleted) {
            return response()->json([
                'success' => true,
                'message' => 'All tasks are not completed',
                'all_completed' => false,
                'remaining_hours' => 0
            ]);
        }

        // 3. Get project details
        $project = DB::table('projects_master')->where('id', $projectId)->first();

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }

        $totalHours = (float) $project->project_hours;
        $workingHours = (float) $project->project_used_hours;
        $remainingHours = max(0, $totalHours - $workingHours);

        // 4. Get all Non Billable entries for this project from performa_sheets
        $entries = PerformaSheet::where('status', 'approved')->get()->filter(function ($entry) use ($projectId) {
            $data = json_decode($entry->data, true);
            return isset($data['project_id'], $data['activity_type']) &&
                $data['project_id'] == $projectId &&
                $data['activity_type'] == 'Non Billable';
        });

        $converted = [];
        $remaining = $remainingHours;

        foreach ($entries as $entry) {
            $data = json_decode($entry->data, true);
            $entryHours = timeToFloat($data['time']); // helper to convert HH:MM to float
            if ($remaining <= 0)
                break;

            if ($entryHours <= $remaining) {
                // Fully convert to Billable
                // $data['activity_type'] = 'Billable';
                $data['message'] = 'Converted from Non Billable to Billable via Sync';
                $entry->data = json_encode($data);
                $entry->save();

                $converted[] = $entry;
                $remaining -= $entryHours;
                $workingHours += $entryHours;
            } else {
                // Partially convert: update existing with Billable, create new with leftover Non Billable
                $billableTime = floatToTime($remaining);
                $nonBillableTime = floatToTime($entryHours - $remaining);

                // Update current entry
                $data['time'] = $billableTime;
                // $data['activity_type'] = 'Billable';
                $data['message'] = 'Partially converted to Billable via Sync';
                $entry->data = json_encode($data);
                $entry->save();

                $workingHours += $remaining;

                // Create new Non Billable entry with leftover
                //code changed to billable
                $newData = $data;
                // $newData['activity_type'] = 'Billable';
                $newData['time'] = $nonBillableTime;
                $newData['message'] = 'Remaining Billable after partial conversion';

                $newEntry = PerformaSheet::create([
                    'user_id' => $entry->user_id,
                    'data' => json_encode($newData),
                    'status' => 'approved',
                ]);

                $converted[] = $entry;
                $converted[] = $newEntry;

                $remaining = 0;
            }
        }

        // Update project total_working_hours
        DB::table('projects_master')->where('id', $projectId)->update([
            'project_used_hours' => $workingHours
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Billable entries converted based on remaining hours',
            'converted' => $converted,
            'updated_total_working_hours' => $workingHours,
            'remaining_after_conversion' => max(0, $totalHours - $workingHours),
        ]);
    }

    // Helper: convert "HH:MM" to float (like "01:30" => 1.5)
    private function convertTimeToFloat($time)
    {
        [$hours, $minutes] = explode(':', $time);
        return (int) $hours + ((int) $minutes / 60);
    }

    // Helper: convert float back to "HH:MM"
    private function convertFloatToTime($float)
    {
        $hours = floor($float);
        $minutes = round(($float - $hours) * 60);
        return str_pad($hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad($minutes, 2, '0', STR_PAD_LEFT);
    }

    public function getPerformaManagerEmp()
    {
        $projectManager = auth()->user();
        $teamId = $projectManager->team_id;
        $sheets = PerformaSheet::with(['user:id,name,team_id'])
            ->whereHas('user', function ($query) use ($teamId) {
                $query->where('team_id', $teamId);
            })
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Performa Sheets fetched successfully',
            'project_manager_id' => $projectManager->id,
            'team_id' => $teamId,
            'data' => $sheets
        ]);

        $structuredData = [];
        foreach ($sheets as $sheet) {
            $dataArray = json_decode($sheet->data, true);
            if (!is_array($dataArray)) {
                continue;
            }
            $projectId = $dataArray['project_id'] ?? null;
            $date = $dataArray['date'] ?? '0000-00-00';
            $project = $projectId ? Project::with('client:id,name')->find($projectId) : null;
            $projectName = $project->project_name ?? 'No Project Found';
            $clientName = $project->client->name ?? 'No Client Found';
            $deadline = $project->deadline ?? 'No Deadline Set';

            $tagActivityIds = [];

            if ($project && is_array($project->tags_activitys)) {
                $tagActivityIds = $project->tags_activitys;
            } elseif ($project && is_string($project->tags_activitys)) {
                $tagActivityIds = json_decode($project->tags_activitys, true) ?? [];
            }

            // Fetch tag activity names from database
            $tagActivityNames = [];
            if (!empty($tagActivityIds)) {
                $tagActivityNames = TagsActivity::whereIn('id', $tagActivityIds)->pluck('name')->toArray();
            }

            $dataArray['project_name'] = $projectName;
            $dataArray['client_name'] = $clientName;
            $dataArray['deadline'] = $deadline;
            $dataArray['status'] = $sheet->status ?? 'pending';
            $dataArray['user_id'] = $sheet->user->id;
            $dataArray['user_name'] = $sheet->user->name;
            $dataArray['performa_sheet_id'] = $sheet->id;
            $dataArray['tag_activity_ids'] = $tagActivityIds;
            $dataArray['tag_activity_names'] = $tagActivityNames;

            $structuredData[] = $dataArray;
        }
        // echo "<pre>";
        // print_r($structuredData);
        // echo "</pre>";
        // die();


        $structuredData = collect($structuredData)->sortByDesc('date')->values()->toArray();
        return response()->json([
            'success' => true,
            'message' => 'Performa Sheets fetched successfully',
            'project_manager_id' => $projectManager->id,
            'team_id' => $teamId,
            'data' => $structuredData
        ]);
    }


    public function editPerformaSheets(Request $request)
    {
        $user = auth()->user();

        try {
            $validatedData = $request->validate([
                'id' => 'required|exists:performa_sheets,id',
                'data' => 'required|array',
                'data.project_id' => [
                    'required',
                    Rule::exists('project_relations', 'project_id')->where(function ($query) use ($user) {
                        $query->whereRaw(
                            'JSON_CONTAINS(assignees, ?, "$")',
                            [json_encode((int) $user->id)]
                        );
                    })
                ],
                'data.date' => 'required|date_format:Y-m-d',
                'data.time' => 'required|date_format:H:i',
                'data.work_type' => 'required|string|max:255',
                'data.narration' => 'nullable|string',
                'data.is_tracking' => 'required|in:yes,no',
                'data.tracking_mode' => 'nullable|in:all,partial',
                'data.tracked_hours' => 'nullable',
                // 'data.offline_hours' => 'nullable',
                'data.is_fillable' => 'nullable|boolean',
                // 'data.status' => 'nullable',
            ]);

            $projectId = $validatedData['data']['project_id'];
            $project = ProjectMaster::find($projectId);
            $projectName = $project ? $project->name : "Unknown Project";
            $tasks = Task::where('project_id', $projectId)->get();

            if ($tasks->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "No task exists for the selected project: {$projectName}"
                ], 402);
            }

            $allowedStatuses = ['to do', 'in progress'];
            $validTaskExists = $tasks->contains(function ($task) use ($allowedStatuses) {
                return in_array(strtolower($task->status), $allowedStatuses);
            });

            if (!$validTaskExists) {
                return response()->json([
                    'success' => false,
                    'message' => "All tasks for project '{$projectName}' are either completed or not started. Please update task status to 'To do' or 'In progress'."
                ], 402);
            }

            $performaSheet = PerformaSheet::where('id', $validatedData['id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$performaSheet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Performa Sheet not found or you do not have permission to edit it.'
                ], 404);
            }



            $oldData = json_decode($performaSheet->data, true);
            $newData = array_merge($oldData, $validatedData['data']);
            $oldStatus = $performaSheet->status;
            // $newData = $validatedData['data'];

            if ($newData['is_tracking'] === 'yes' && $project && $project->project_tracking) {
                if ($newData['tracking_mode'] === 'all') {
                    $newData['tracked_hours'] = $newData['time'];
                    $newData['offline_hours'] = '00:00';

                } else if ($newData['tracking_mode'] === 'partial') {
                    if (empty($newData['tracked_hours'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Tracked hours are required when tracking mode is partial.'
                        ], 422);
                    }
                    if ((int) $project->offline_hours !== 1) {
                        return response()->json([
                            'success' => false,
                            'message' => "Offline hours are not allowed for the project '{$project->project_name}'."
                        ], 422);
                    }

                    $totalMinutes = $this->timeToMinutes($newData['time']);
                    $trackedMinutes = $this->timeToMinutes($newData['tracked_hours']);

                    if ($trackedMinutes > $totalMinutes) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Tracked hours cannot be greater than total time.'
                        ], 422);
                    }
                    $offlineMinutes = $totalMinutes - $trackedMinutes;
                    $newData['offline_hours'] = $this->minutesToTime($offlineMinutes);
                }
            } else {
                $newData['is_tracking'] = 'no';
                $newData['tracking_mode'] = '';
                $newData['tracked_hours'] = '00:00';
                $newData['offline_hours'] = '00:00';
            }

            if ($project && $project->project_tracking) {
                $newData['activity_type'] = 'Billable';
                $newData['project_type'] = 'Hourly';
                $newData['project_type_status'] = 'Online';
            }

            $isChanged = $oldData != $newData;

            if ($isChanged) {
                if (in_array(strtolower($oldStatus), ['standup', 'backdated'])) {
                    $performaSheet->status = $oldStatus;
                } else {
                    if (in_array(strtolower($oldStatus), ['approved', 'rejected'])) {
                        $performaSheet->status = 'pending';
                    }else{
                        $performaSheet->status = $oldStatus;
                    }
                }
                $performaSheet->data = json_encode($newData);
                $performaSheet->save();

                ActivityService::log([
                    'project_id' => $project->id,
                    'type' => 'activity',
                    'description' => 'Performa Sheets updated by ' . auth()->user()->name,
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Performa Sheet updated successfully',
                    'status' => $performaSheet->status,
                    'data' => $performaSheet
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'No changes detected.',
                    'status' => $oldStatus,
                    'data' => $performaSheet
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function deletePerformaSheets(Request $request)
    {
        $userId = auth()->id();
        $sheetId = $request->input('sheet_id');
        if (!$sheetId) {
            return response()->json([
                'success' => false,
                'message' => 'Sheet ID is required.',
            ], 400);
        }
        $sheet = PerformaSheet::where('id', $sheetId)
            ->where('user_id', $userId)
            ->first();
        if (!$sheet) {
            return response()->json([
                'success' => false,
                'message' => 'Sheet not found or not owned by you.',
            ], 404);
        }
        if ($sheet->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete rejected sheets.',
            ], 403);
        }
        if ($sheet->status === 'rejected' || $sheet->status === 'standup') {
            $sheet->delete();

            return response()->json([
                'success' => true,
                'message' => 'Performa sheet deleted successfully.',
            ]);
        }
        return response()->json([
            'success' => false,
            'message' => 'Only rejected and standup sheets can be deleted.',
        ], 403);
    }


    public function approveRejectPerformaSheets(Request $request)
    {
        $request->validate([
            'ids' => 'required',
            'status' => 'required|string|in:approved,rejected'
        ]);

        $ids = is_array($request->ids) ? $request->ids : [$request->ids];
        $performaSheets = PerformaSheet::whereIn('id', $ids)->get();

        if ($performaSheets->isEmpty()) {
            return response()->json(['message' => 'Invalid performa sheet ID(s).'], 404);
        }

        $results = [];
        foreach ($performaSheets as $performa) {
            $originalData = json_decode($performa->data, true);
            $oldStatus = $performa->status;

            // === CASE 1: Reject flow with reverse approved ===
            if ($request->status === 'rejected') {

                if ($oldStatus === 'approved') {
                    $project = Project::find($originalData['project_id']);

                    if ($project && !empty($originalData['time'])) {
                        try {
                            $submittedTime = \Carbon\Carbon::createFromFormat('H:i', trim($originalData['time']));
                            $submittedHours = $submittedTime->hour + ($submittedTime->minute / 60);

                            if ($submittedHours > 0) {
                                $project->total_working_hours = max(0, $project->total_working_hours - $submittedHours);
                                $project->remaining_hours = ($project->remaining_hours ?? 0) + $submittedHours;

                                if (isset($project->total_hours)) {
                                    $project->total_hours = ($project->total_hours ?? 0) + $submittedHours;
                                }

                                $project->save();
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }
                $performa->status = 'rejected';
                $performa->save();

                $results[] = [
                    'performa_id' => $performa->id,
                    'status' => 'rejected',
                    'note' => 'Status updated to rejected' . ($oldStatus === 'approved' ? ' and project hours reversed' : '')
                ];
                continue;
            }

            // === CASE 2: Inhouse approved ===

            // if (strtolower($originalData['activity_type']) === 'inhouse' && $request->status === 'approved') {
            if ((strtolower($originalData['activity_type']) == 'inhouse' || strtolower($originalData['activity_type']) == 'in-house') && $request->status === 'approved') {
                // $originalData['activity_type'] = 'inhouse';
                $performa->status = 'approved';
                $performa->data = json_encode($originalData);
                $performa->save();

                $results[] = [
                    'performa_id' => $performa->id,
                    'status' => 'approved',
                    'note' => 'Inhouse activity approved as inhouse (no billable/non-billable change)'
                ];
                continue;
            }

            // === Get project for approved cases ===
            $project = Project::find($originalData['project_id']);
            if (!$project) {
                $results[] = [
                    'performa_id' => $performa->id,
                    'status' => 'skipped',
                    'note' => 'Project not found'
                ];
                continue;
            }
            if (strtolower($project->project_type) === 'fixed') {
                $submittedTime = \Carbon\Carbon::createFromFormat('H:i', trim($originalData['time']));
                $submittedHours = $submittedTime->hour + ($submittedTime->minute / 60);

                if (strtolower($originalData['activity_type']) === 'non billable') {
                    $performa->data = json_encode($originalData);
                    $performa->status = 'approved';
                    $performa->save();

                    $project->total_working_hours += (float) $submittedHours;
                    $project->save();

                    $results[] = [
                        'performa_id' => $performa->id,
                        'status' => 'approved',
                        'note' => 'Non Billable entry for fixed project - added to total working hours only'
                    ];
                } else {
                    $remainingHours = (float) ($project->remaining_hours ?? 0);
                    $total = max(0, $remainingHours - $submittedHours);

                    if ($submittedHours <= $remainingHours) {
                        $billableHours = $submittedHours;
                        $extraHours = 0;
                    } else {
                        $billableHours = $remainingHours;
                        $extraHours = $submittedHours - $remainingHours;
                    }

                    $formatTime = function ($hours) {
                        return sprintf('%02d:%02d', floor($hours), round(($hours - floor($hours)) * 60));
                    };

                    if ($billableHours > 0) {
                        $billableData = $originalData;
                        $billableData['time'] = $formatTime($billableHours);
                        // $billableData['activity_type'] = 'Billable';
                        $billableData['message'] = 'Billable - within remaining hours';
                        $performa->data = json_encode($billableData);
                        $performa->status = 'approved';
                        $performa->save();
                        $project->remaining_hours = $total;
                        $results[] = [
                            'performa_id' => $performa->id,
                            'status' => 'approved',
                            'note' => 'Billable portion updated based on remaining hours'
                        ];
                    }
                    // if ($extraHours > 0) {
                    //     $nonBillableData = $originalData;
                    //     $nonBillableData['time'] = $formatTime($extraHours);
                    //     $nonBillableData['activity_type'] = 'Non Billable';
                    //     $nonBillableData['message'] = 'Non Billable - extra time beyond remaining hours';
                    //     $newPerforma = new PerformaSheet();
                    //     $newPerforma->user_id = $performa->user_id;
                    //     $newPerforma->status = 'approved';
                    //     $newPerforma->data = json_encode($nonBillableData);
                    //     $newPerforma->save();
                    //     $results[] = [
                    //         'performa_id' => $newPerforma->id,
                    //         'status' => 'approved',
                    //         'note' => 'New non-billable entry created for extra hours'
                    //     ];
                    // }
                    if ($extraHours > 0 && $remainingHours == 0) {
                        // Update existing performa sheet instead of creating new
                        $originalData['time'] = $formatTime($submittedHours);  // full submitted hours
                        // $originalData['activity_type'] = 'Billable';
                        $originalData['message'] = 'Billable - remaining hours finished, updated as billable';
                        $performa->data = json_encode($originalData);
                        $performa->status = 'approved';
                        $performa->save();

                        $results[] = [
                            'performa_id' => $performa->id,
                            'status' => 'approved',
                            'note' => 'Existing performa updated to billable as remaining hours are finished'
                        ];
                    } elseif ($extraHours > 0) {
                        // Existing logic for when remainingHours > 0 and extraHours exist
                        $nonBillableData = $originalData;
                        $nonBillableData['time'] = $formatTime($extraHours);
                        // $nonBillableData['activity_type'] = 'Billable';
                        $nonBillableData['message'] = 'Billable - extra time beyond remaining hours';
                        $newPerforma = new PerformaSheet();
                        $newPerforma->user_id = $performa->user_id;
                        $newPerforma->status = 'approved';
                        $newPerforma->data = json_encode($nonBillableData);
                        $newPerforma->save();
                        $results[] = [
                            'performa_id' => $newPerforma->id,
                            'status' => 'approved',
                            'note' => 'New billable entry created for extra hours'
                        ];
                    }

                    $project->total_working_hours += (float) $submittedHours;
                    $project->save();
                }
                continue;
            }
            $originalData['message'] = $originalData['activity_type'] === 'non billable'
                ? 'Non Billable - approved'
                : 'Billable - approved';
            $performa->status = 'approved';
            $performa->data = json_encode($originalData);
            $performa->save();
            $results[] = [
                'performa_id' => $performa->id,
                'status' => 'approved',
                'note' => 'Performa updated with activity type'
            ];
        }
        ActivityService::log([
            'project_id' => $project->id,
            'type' => 'activity',
            'description' => 'Performa Sheets Status updated by ' . auth()->user()->name,
        ]);
        return response()->json([
            'message' => 'Performa sheets processed successfully.',
            'results' => $results
        ]);
    }
    public function approveRejectPerformaSheetsMaster(Request $request)
    {
        $request->validate([
            'ids' => 'required',
            'status' => 'required|string|in:approved,rejected'
        ]);

        $ids = is_array($request->ids) ? $request->ids : [$request->ids];
        $performaSheets = PerformaSheet::whereIn('id', $ids)->get();

        if ($performaSheets->isEmpty()) {
            return response()->json(['message' => 'Invalid performa sheet ID(s).'], 404);
        }

        $results = [];
        foreach ($performaSheets as $performa) {
            $originalData = json_decode($performa->data, true);
            $oldStatus = $performa->status;

            // === CASE 1: Reject flow with reverse approved ===
            if ($request->status === 'rejected') {

                if ($oldStatus === 'approved') {
                    $project = ProjectMaster::find($originalData['project_id']);

                    if ($project && !empty($originalData['time'])) {
                        try {
                            $submittedTime = \Carbon\Carbon::createFromFormat('H:i', trim($originalData['time']));
                            $submittedHours = $submittedTime->hour + ($submittedTime->minute / 60);

                            if ($submittedHours > 0) {
                                $project->project_used_hours = max(0, $project->project_used_hours - $submittedHours);
                                $remaining_hours = ($project->project_hours - $project->project_used_hours) + $submittedHours;

                                if (isset($project->total_hours)) {
                                    $project->total_hours = ($project->total_hours ?? 0) + $submittedHours;
                                }

                                $project->save();
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }
                $performa->status = 'rejected';
                $performa->save();

                $results[] = [
                    'performa_id' => $performa->id,
                    'status' => 'rejected',
                    'note' => 'Status updated to rejected' . ($oldStatus === 'approved' ? ' and project hours reversed' : '')
                ];
                continue;
            }

            // === CASE 2: Inhouse approved ===

            // if (strtolower($originalData['activity_type']) === 'inhouse' && $request->status === 'approved') {
            if ((strtolower($originalData['activity_type']) == 'inhouse' || strtolower($originalData['activity_type']) == 'in-house') && $request->status === 'approved') {
                // $originalData['activity_type'] = 'inhouse';
                $performa->status = 'approved';
                $performa->data = json_encode($originalData);
                $performa->save();

                $results[] = [
                    'performa_id' => $performa->id,
                    'status' => 'approved',
                    'note' => 'Inhouse activity approved as inhouse (no billable/non-billable change)'
                ];
                continue;
            }

            // === Get project for approved cases ===
            $project = ProjectMaster::find($originalData['project_id']);
            if (!$project) {
                $results[] = [
                    'performa_id' => $performa->id,
                    'status' => 'skipped',
                    'note' => 'Project not found'
                ];
                continue;
            }
            if (!$project->project_tracking) {
                $submittedTime = \Carbon\Carbon::createFromFormat('H:i', trim($originalData['time']));
                $submittedHours = $submittedTime->hour + ($submittedTime->minute / 60);

                if (strtolower($originalData['activity_type']) === 'non billable') {
                    $performa->data = json_encode($originalData);
                    $performa->status = 'approved';
                    $performa->save();

                    $project->project_used_hours += (float) $submittedHours;
                    $project->save();

                    $results[] = [
                        'performa_id' => $performa->id,
                        'status' => 'approved',
                        'note' => 'Non Billable entry for fixed project - added to total working hours only'
                    ];
                } else {
                    $remainingHours = (float) ($project->project_hours - $project->project_used_hours) ?? 0;
                    $total = max(0, $remainingHours - $submittedHours);

                    if ($submittedHours <= $remainingHours) {
                        $billableHours = $submittedHours;
                        $extraHours = 0;
                    } else {
                        $billableHours = $remainingHours;
                        $extraHours = $submittedHours - $remainingHours;
                    }

                    $formatTime = function ($hours) {
                        return sprintf('%02d:%02d', floor($hours), round(($hours - floor($hours)) * 60));
                    };

                    if ($billableHours > 0) {
                        $billableData = $originalData;
                        $billableData['time'] = $formatTime($billableHours);
                        // $billableData['activity_type'] = 'Billable';
                        $billableData['message'] = 'Billable - within remaining hours';
                        $performa->data = json_encode($billableData);
                        $performa->status = 'approved';
                        $performa->save();
                        // $project->remaining_hours = $total;
                        $results[] = [
                            'performa_id' => $performa->id,
                            'status' => 'approved',
                            'note' => 'Billable portion updated based on remaining hours'
                        ];
                    }
                    // if ($extraHours > 0) {
                    //     $nonBillableData = $originalData;
                    //     $nonBillableData['time'] = $formatTime($extraHours);
                    //     $nonBillableData['activity_type'] = 'Non Billable';
                    //     $nonBillableData['message'] = 'Non Billable - extra time beyond remaining hours';
                    //     $newPerforma = new PerformaSheet();
                    //     $newPerforma->user_id = $performa->user_id;
                    //     $newPerforma->status = 'approved';
                    //     $newPerforma->data = json_encode($nonBillableData);
                    //     $newPerforma->save();
                    //     $results[] = [
                    //         'performa_id' => $newPerforma->id,
                    //         'status' => 'approved',
                    //         'note' => 'New non-billable entry created for extra hours'
                    //     ];
                    // }
                    if ($extraHours > 0 && $remainingHours == 0) {
                        // Update existing performa sheet instead of creating new
                        $originalData['time'] = $formatTime($submittedHours);  // full submitted hours
                        // $originalData['activity_type'] = 'Billable';
                        $originalData['message'] = 'Billable - remaining hours finished, updated as billable';
                        $performa->data = json_encode($originalData);
                        $performa->status = 'approved';
                        $performa->save();

                        $results[] = [
                            'performa_id' => $performa->id,
                            'status' => 'approved',
                            'note' => 'Existing performa updated to billable as remaining hours are finished'
                        ];
                    } elseif ($extraHours > 0) {
                        // Existing logic for when remainingHours > 0 and extraHours exist
                        $nonBillableData = $originalData;
                        $nonBillableData['time'] = $formatTime($extraHours);
                        // $nonBillableData['activity_type'] = 'Billable';
                        $nonBillableData['message'] = 'Billable - extra time beyond remaining hours';
                        $newPerforma = new PerformaSheet();
                        $newPerforma->user_id = $performa->user_id;
                        $newPerforma->status = 'approved';
                        $newPerforma->data = json_encode($nonBillableData);
                        $newPerforma->save();
                        $results[] = [
                            'performa_id' => $newPerforma->id,
                            'status' => 'approved',
                            'note' => 'New billable entry created for extra hours'
                        ];
                    }

                    $project->project_used_hours += (float) $submittedHours;
                    $project->save();
                }
                continue;
            }
            $originalData['message'] = $originalData['activity_type'] === 'non billable'
                ? 'Non Billable - approved'
                : 'Billable - approved';
            $performa->status = 'approved';
            $performa->data = json_encode($originalData);
            $performa->save();
            $results[] = [
                'performa_id' => $performa->id,
                'status' => 'approved',
                'note' => 'Performa updated with activity type'
            ];
            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Performa Sheets Status updated by ' . auth()->user()->name,
            ]);
        }
        return response()->json([
            'message' => 'Performa sheets processed successfully.',
            'results' => $results
        ]);
    }

    // public function getAllPendingPerformaSheets(Request $request)
    // {
    //     $user = $request->user();
    //     $role_id = $user->role_id;
    //     $team_id = $user->team_id ?? [];

    //     $baseQuery = PerformaSheet::with('user:id,name');

    //     if ($role_id == 7) {
    //         $baseQuery->where('user_id', $user->id);
    //     } else if ($role_id == 1 || $role_id == 2 || $role_id == 3 || $role_id == 4) {
    //         //for admins, hr
    //         $teamMemberIds = User::where('role_id', 7)->where("is_active", 1)->pluck('id')
    //             ->toArray();
    //         $baseQuery->whereIn('user_id', $teamMemberIds);

    //     } else if (!empty($team_id)) {
    //         $teamMemberIds = User::where('role_id', 7)->where("is_active", 1)
    //             ->where(function ($q) use ($team_id) {
    //                 foreach ($team_id as $t) {
    //                     $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
    //                 }
    //             })
    //             ->pluck('id')
    //             ->toArray();

    //         $baseQuery->whereIn('user_id', $teamMemberIds);
    //     }

    //     $baseQuery->where('status', 'pending');

    //     $baseQuery->orderBy('id', 'DESC');

    //     $sheets = $baseQuery->get();

    //     $structuredData = [];

    //     foreach ($sheets as $sheet) {
    //         $dataArray = json_decode($sheet->data, true);

    //         if (!is_array($dataArray)) {
    //             continue;
    //         }

    //         $projectId = $dataArray['project_id'] ?? null;
    //         $project = $projectId ? ProjectMaster::with('client')->find($projectId) : null;

    //         $projectName = $project->project_name ?? 'No Project Found';
    //         $clientName = $project->client->client_name ?? 'No Client Found';
    //         $deadline = $project->deadline ?? 'No Deadline Set';

    //         // Remove unwanted keys
    //         unset($dataArray['user_id'], $dataArray['user_name']);

    //         // Inject meta values
    //         $dataArray['project_name'] = $projectName;
    //         $dataArray['client_name'] = $clientName;
    //         $dataArray['deadline'] = $deadline;
    //         $dataArray['status'] = $sheet->status;
    //         $dataArray['id'] = $sheet->id;

    //         $dataArray['created_at'] = $sheet->created_at
    //             ? \Carbon\Carbon::parse($sheet->created_at)->format('Y-m-d H:i:s') : '';

    //         $dataArray['updated_at'] = $sheet->updated_at
    //             ? \Carbon\Carbon::parse($sheet->updated_at)->format('Y-m-d H:i:s') : '';

    //         // Group by user
    //         if (!isset($structuredData[$sheet->user_id])) {
    //             $structuredData[$sheet->user_id] = [
    //                 'user_id' => $sheet->user_id,
    //                 'user_name' => $sheet->user ? $sheet->user->name : 'No User Found',
    //                 'sheets' => []
    //             ];
    //         }

    //         $structuredData[$sheet->user_id]['sheets'][] = $dataArray;
    //     }

    //     $structuredData = array_values($structuredData);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'All pending Performa Sheets fetched successfully',
    //         'data' => $structuredData
    //     ]);
    // }


    public function getAllPendingPerformaSheets(Request $request)
    {
        $user = $request->user();
        $team_id = $user->team_id ?? [];

        $baseQuery = PerformaSheet::with('user:id,name');


        if ($user->hasRole(7)) {

            $baseQuery->where('user_id', $user->id);
        } elseif ($user->hasAnyRole([1, 2, 3, 4])) {

            $teamMemberIds = User::whereJsonContains('role_id', 7)
                ->where('is_active', 1)
                ->pluck('id')
                ->toArray();

            $baseQuery->whereIn('user_id', $teamMemberIds);
        } elseif (!empty($team_id)) {

            $teamMemberIds = User::whereJsonContains('role_id', 7)
                ->where('is_active', 1)
                ->where(function ($q) use ($team_id) {
                    foreach ($team_id as $t) {
                        $q->orWhereRaw(
                            'JSON_CONTAINS(team_id, ?)',
                            [json_encode($t)]
                        );
                    }
                })
                ->pluck('id')
                ->toArray();

            $baseQuery->whereIn('user_id', $teamMemberIds);
        }

        $baseQuery->where('status', 'pending')
            ->orderBy('id', 'DESC');

        $sheets = $baseQuery->get();

        $structuredData = [];

        foreach ($sheets as $sheet) {

            $dataArray = json_decode($sheet->data, true);
            if (!is_array($dataArray)) {
                continue;
            }

            $projectId = $dataArray['project_id'] ?? null;
            $project = $projectId
                ? ProjectMaster::with('client')->find($projectId)
                : null;

            unset($dataArray['user_id'], $dataArray['user_name']);

            $dataArray['project_name'] = $project->project_name ?? 'No Project Found';
            $dataArray['client_name'] = $project->client->client_name ?? 'No Client Found';
            $dataArray['deadline'] = $project->deadline ?? 'No Deadline Set';
            $dataArray['status'] = $sheet->status;
            $dataArray['id'] = $sheet->id;
            $dataArray['created_at'] = optional($sheet->created_at)->format('Y-m-d H:i:s');
            $dataArray['updated_at'] = optional($sheet->updated_at)->format('Y-m-d H:i:s');

            if (!isset($structuredData[$sheet->user_id])) {
                $structuredData[$sheet->user_id] = [
                    'user_id' => $sheet->user_id,
                    'user_name' => $sheet->user->name ?? 'No User Found',
                    'sheets' => []
                ];
            }

            $structuredData[$sheet->user_id]['sheets'][] = $dataArray;
        }

        return response()->json([
            'success' => true,
            'message' => 'All pending Performa Sheets fetched successfully',
            'data' => array_values($structuredData)
        ]);
    }

    public function getAllDraftPerformaSheets(Request $request)
    {
        $user = $request->user();
        $role_id = $user->role_id;
        $team_id = $user->team_id ?? [];
        $isFillable = $request->has('is_fillable') ? (int) $request->query('is_fillable') : null;

        $baseQuery = PerformaSheet::with('user:id,name');
        $baseQuery->where('user_id', $user->id);
        if ($isFillable) {
            $baseQuery->where('status', 'standup');
        } else {
            $baseQuery->where('status', 'backdated');
        }

        $baseQuery->orderBy('id', 'DESC');

        $sheets = $baseQuery->get();

        // Prepare structured data
        $structuredData = [];

        foreach ($sheets as $sheet) {
            $dataArray = json_decode($sheet->data, true);

            if (!is_array($dataArray)) {
                continue;
            }

            // Skip if filter is applied and the row doesn't match
            if ($isFillable !== null) {
                if (!isset($dataArray['is_fillable']) || (int) $dataArray['is_fillable'] !== $isFillable) {
                    continue;
                }
            }

            $projectId = $dataArray['project_id'] ?? null;
            $project = $projectId ? ProjectMaster::with('client')->find($projectId) : null;

            $projectName = $project->project_name ?? 'No Project Found';
            $clientName = $project->client->client_name ?? 'No Client Found';
            $deadline = $project->deadline ?? 'No Deadline Set';

            // Remove unwanted keys
            unset($dataArray['user_id'], $dataArray['user_name']);

            // Inject meta values
            $dataArray['project_name'] = $projectName;
            $dataArray['client_name'] = $clientName;
            $dataArray['deadline'] = $deadline;
            $dataArray['status'] = $sheet->status;
            $dataArray['id'] = $sheet->id;
            $dataArray['created_at'] = $sheet->created_at
                ? \Carbon\Carbon::parse($sheet->created_at)->format('Y-m-d H:i:s') : '';
            $dataArray['updated_at'] = $sheet->updated_at
                ? \Carbon\Carbon::parse($sheet->updated_at)->format('Y-m-d H:i:s') : '';

            // Group by user
            if (!isset($structuredData[$sheet->user_id])) {
                $structuredData[$sheet->user_id] = [
                    'user_id' => $sheet->user_id,
                    'user_name' => $sheet->user ? $sheet->user->name : 'No User Found',
                    'sheets' => []
                ];
            }

            $structuredData[$sheet->user_id]['sheets'][] = $dataArray;
        }

        $structuredData = array_values($structuredData);

        return response()->json([
            'success' => true,
            'message' => 'All pending Performa Sheets fetched successfully',
            'data' => $structuredData
        ]);
    }
    public function getAllStandupPerformaSheets(Request $request)
    {
        try {
            $user = $request->user();
            $team_id = $user->team_id ?? [];

            if ($request->has('date')) {
                $start = $end = Carbon::parse($request->date)->toDateString();
            } elseif ($request->has('start_date') && !$request->has('end_date')) {
                $start = Carbon::parse($request->start_date)->toDateString();
                $end = Carbon::today()->toDateString();
            } elseif (!$request->has('start_date') && $request->has('end_date')) {
                $start = '1970-01-01';
                $end = Carbon::parse($request->end_date)->toDateString();
            } else {
                $start = $request->start_date
                    ? Carbon::parse($request->start_date)->toDateString()
                    : null;
                $end = $request->end_date
                    ? Carbon::parse($request->end_date)->toDateString()
                    : null;
            }

            $baseQuery = PerformaSheet::with('user:id,name')
                ->where('status', 'standup');

            // Role 7 → only own sheets
            if ($user->hasRole(7)) {
                $baseQuery->where('user_id', $user->id);
            }
            // Admins / HR → Role 1,2,3,4 → all employees
            elseif ($user->hasAnyRole([1, 2, 3, 4])) {
                $teamMemberIds = User::whereJsonContains('role_id', 7)
                    ->where("is_active", 1)
                    ->pluck('id')
                    ->toArray();

                $baseQuery->whereIn('user_id', $teamMemberIds);
            } elseif (!empty($team_id)) {
                $teamMemberIds = User::whereJsonContains('role_id', 7)
                    ->where("is_active", 1)
                    ->where(function ($q) use ($team_id) {
                        foreach ($team_id as $t) {
                            $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                        }
                    })
                    ->pluck('id')
                    ->toArray();

                $baseQuery->whereIn('user_id', $teamMemberIds);
            }

            $sheets = $baseQuery
                ->orderBy('id', 'DESC')
                ->get();

            $allUsersOnLeave = collect();

            $structuredData = $sheets
                ->map(function ($sheet) use ($team_id, $start, $end, $allUsersOnLeave) {

                    $data = json_decode($sheet->data, true);
                    if (!is_array($data) || !isset($data['date'])) {
                        return null;
                    }

                    $sheetDate = $data['date'];

                    if ($start && $end) {
                        if ($sheetDate < $start || $sheetDate > $end) {
                            return null;
                        }
                    } elseif ($start && $sheetDate !== $start) {
                        return null;
                    }

                    $project = isset($data['project_id'])
                        ? ProjectMaster::with(['relation', 'client'])->find($data['project_id'])
                        : null;

                    $assignees = $project->relation->assignees ?? [];

                    if (is_string($assignees)) {
                        $assignees = json_decode($assignees, true) ?? [];
                    }

                    if (!is_array($assignees)) {
                        $assignees = [];
                    }

                    $project_manager_ids = User::whereIn('id', $assignees)
                        ->whereJsonContains('role_id', 5)
                        ->where("is_active", 1)
                        ->where(function ($q) use ($team_id) {
                            foreach ($team_id as $t) {
                                if ($t !== null) {
                                    $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                                }
                            }
                        })
                        ->get();

                    $teamIds = collect($project_manager_ids)
                        ->pluck('team_id')
                        ->flatten()
                        ->unique()
                        ->values()
                        ->toArray();

                    $users_on_leave = User::whereJsonContains('role_id', [6, 7])
                        ->where("is_active", 1)
                        ->where(function ($q) use ($teamIds) {
                            foreach ($teamIds as $t) {
                                if ($t !== null) {
                                    $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                                }
                            }
                        })
                        ->whereHas('leaves', function ($q) use ($sheetDate) {
                            $q->whereDate('start_date', '<=', $sheetDate)
                                ->whereDate('end_date', '>=', $sheetDate)
                                ->where('status', 'approved');
                        })
                        ->with([
                            'leaves' => function ($q) use ($sheetDate) {
                                $q->whereDate('start_date', '<=', $sheetDate)
                                    ->whereDate('end_date', '>=', $sheetDate);
                            }
                        ])->get();

                    $allUsersOnLeave->push($users_on_leave);

                    return [
                        'user_id' => $sheet->user_id,
                        'user_name' => $sheet->user?->name ?? 'No User',
                        'sheet' => [
                            'id' => $sheet->id,
                            'date' => $sheetDate,
                            'time' => $data['time'] ?? null,
                            'project_id' => $data['project_id'] ?? null,
                            'project_name' => $project->project_name ?? 'No Project',
                            'client_name' => $project->client->client_name ?? 'No Client',
                            'work_type' => $data['work_type'] ?? null,
                            'activity_type' => $data['activity_type'] ?? null,
                            'narration' => $data['narration'] ?? null,
                            'status' => $sheet->status,
                            'project_managers' => $project_manager_ids ?? null,
                            'created_at' => $sheet->created_at?->format('Y-m-d H:i:s'),
                            'updated_at' => $sheet->updated_at?->format('Y-m-d H:i:s'),
                        ]
                    ];
                })
                ->filter()
                ->groupBy('user_id')
                ->map(function ($items) {
                    return [
                        'user_id' => $items->first()['user_id'],
                        'user_name' => $items->first()['user_name'],
                        'sheets' => $items->pluck('sheet')->values()
                    ];
                })
                ->values();
            $usersOnLeaveFinal = $allUsersOnLeave
                ->flatten()
                ->unique('id')
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Standup performa sheets fetched successfully',
                'data' => $structuredData,
                'users_on_leave' => $usersOnLeaveFinal
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getUserWeeklyPerformaSheets(Request $request)
    {
        $user = auth()->user();
        try {
            $weeklyTotals = [];

            $selectedDate = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::today();

            $startOfWeek = $selectedDate->copy()->startOfWeek();
            $endOfWeek = $selectedDate->copy()->endOfWeek();
            $period = new \DatePeriod($startOfWeek, \DateInterval::createFromDateString('1 day'), $endOfWeek->copy()->addDay(false));

            /** is passed date fillable for performa sheet */
            $today = Carbon::today();
            $approvedApplications = ApplicationPerforma::where('user_id', $user->id)
                ->whereIn('status', ['approved','pending'])
                ->pluck('apply_date')
                ->map(fn($d) => Carbon::parse($d)->toDateString())
                ->toArray();

            $sheets = PerformaSheet::with('user:id,name')
                ->where('user_id', $user->id)->whereIn('status', ['approved', 'pending'])
                ->get()
                ->filter(function ($sheet) use ($startOfWeek, $endOfWeek) {
                    $data = json_decode($sheet->data, true);
                    if (!$data || !isset($data['date']))
                        return false;

                    $date = $data['date'];
                    return $date >= $startOfWeek->toDateString() &&
                        $date <= $endOfWeek->toDateString();
                })
                ->values();

            // Initialize weekly totals
            foreach ($period as $day) {
                $carbonDay = Carbon::instance($day);
                $dateKey = $carbonDay->toDateString();

                /* is_fillable calculation per day*/
                $isFillable = 1;
                if (!in_array($dateKey, $approvedApplications)) {
                    if ($carbonDay->lt($today)) {
                        $workingDays = 0;
                        $cursor = $carbonDay->copy();

                        while ($cursor->lt($today)) {
                            if (!$cursor->isWeekend()) {
                                $workingDays++;
                            }
                            $cursor->addDay();
                        }

                        if ($workingDays > 2) {
                            $isFillable = 0;
                        }
                    }
                }


                $weeklyTotals[$carbonDay->toDateString()] = [
                    'dayname' => $carbonDay->format('D'),
                    'totalHours' => '00:00',
                    'totalBillableHours' => '00:00',
                    'totalNonBillableHours' => '00:00',
                    'is_fillable' => $isFillable
                ];
            }

            $timeToMinutes = function ($time) {
                [$hours, $minutes] = explode(':', $time);
                return intval($hours) * 60 + intval($minutes);
            };

            $minutesToTime = function ($minutes) {
                $h = floor($minutes / 60);
                $m = $minutes % 60;
                return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
            };

            $totalsInMinutes = [];
            $billableInMinutes = [];
            $nonBillableInMinutes = [];

            foreach ($sheets as $sheet) {
                $data = json_decode($sheet->data, true);
                if (!$data || !isset($data['date'], $data['time'], $data['activity_type']))
                    continue;

                $date = $data['date'];
                $time = $data['time'];
                $activityType = strtolower($data['activity_type']); // to handle case variations
                $minutes = $timeToMinutes($time);

                // Total
                $totalsInMinutes[$date] = ($totalsInMinutes[$date] ?? 0) + $minutes;

                // Billable / Non-Billable
                if ($activityType === 'billable') {
                    $billableInMinutes[$date] = ($billableInMinutes[$date] ?? 0) + $minutes;
                } else {
                    $nonBillableInMinutes[$date] = ($nonBillableInMinutes[$date] ?? 0) + $minutes;
                }
            }

            // Assign to weekly totals
            foreach ($weeklyTotals as $date => &$totals) {
                $totals['totalHours'] = isset($totalsInMinutes[$date]) ? $minutesToTime($totalsInMinutes[$date]) : '00:00';
                $totals['totalBillableHours'] = isset($billableInMinutes[$date]) ? $minutesToTime($billableInMinutes[$date]) : '00:00';
                $totals['totalNonBillableHours'] = isset($nonBillableInMinutes[$date]) ? $minutesToTime($nonBillableInMinutes[$date]) : '00:00';
            }

            return response()->json([
                'success' => true,
                'message' => 'Weekly Performa Sheets fetched successfully',
                'data' => $weeklyTotals,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAllUsersWithUnfilledPerformaSheets(Request $request)
    {
        try {
            $authUser = auth()->user();

            // Date range
            if ($request->has('date')) {
                $start = $end = Carbon::parse($request->date)->toDateString();
            } else {
                $start = $request->start_date
                    ? Carbon::parse($request->start_date)->toDateString()
                    : Carbon::today()->toDateString();

                $end = $request->end_date
                    ? Carbon::parse($request->end_date)->toDateString()
                    : $start;

                if ($start > $end) {
                    return response()->json([
                        "success" => false,
                        "message" => "Start date cannot be after end date"
                    ]);
                }
            }

            // All dates in range
            $dates = [];
            $current = Carbon::parse($start);
            while ($current->toDateString() <= $end) {
                $dates[] = $current->toDateString();
                $current->addDay();
            }

            $query = User::whereJsonContains('role_id', 7)
                ->where("is_active", 1);

            // TL
            if ($authUser->hasRole(6)) {
                $query->where(function ($q) use ($authUser) {
                    foreach ($authUser->team_id as $t) {
                        $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($t)]);
                    }
                });
            }

            // PM
            if ($authUser->hasRole(5)) {
                $pmTeams = is_array($authUser->team_id) ? $authUser->team_id : [];
                $query->where(function ($q) use ($pmTeams) {
                    foreach ($pmTeams as $teamId) {
                        $q->orWhereRaw('JSON_CONTAINS(team_id, ?)', [json_encode($teamId)]);
                    }
                });
            }

            // Normal user
            if ($authUser->hasRole(7)) {
                $query->where("id", $authUser->id);
            }

            $users = $query->get();

            $submissions = PerformaSheet::select("user_id", "data")->get()
                ->map(function ($sheet) {
                    $d = json_decode($sheet->data, true);
                    return isset($d["date"])
                        ? ["user_id" => $sheet->user_id, "date" => $d["date"]]
                        : null;
                })
                ->filter()
                ->groupBy("user_id");

            $leaves = LeavePolicy::whereIn("leave_type", ["Full Leave", "Multiple Days Leave"])
                ->where("status", "Approved")
                ->get()
                ->groupBy("user_id");

            $missingDates = [];
            $userResult = [];

            foreach ($users as $user) {
                $teamName = null;

                if (is_array($user->team_id) && count($user->team_id) > 0) {
                    $teamId = $user->team_id[0];
                    $team = \App\Models\Team::find($teamId);
                    $teamName = $team ? $team->name : null;
                }

                $userMissing = [];

                foreach ($dates as $date) {
                    $hasSubmitted = isset($submissions[$user->id]) &&
                        $submissions[$user->id]->contains("date", $date);

                    if ($hasSubmitted)
                        continue;

                    $onLeave = false;
                    if (isset($leaves[$user->id])) {
                        foreach ($leaves[$user->id] as $leave) {
                            if ($leave->start_date <= $date && $leave->end_date >= $date) {
                                $onLeave = true;
                                break;
                            }
                        }
                    }

                    if ($onLeave)
                        continue;

                    $userMissing[] = $date;
                    if (!in_array($date, $missingDates)) {
                        $missingDates[] = $date;
                    }
                }

                if (!empty($userMissing)) {
                    $userResult[] = [
                        "user_id" => $user->id,
                        "name" => $user->name,
                        "email" => $user->email,
                        "tl_id" => $user->tl_id ?? null,
                        "tl_name" => $user->tl ? $user->tl->name : null,
                        "team_id" => $user->team_id ?? [],
                        "team_name" => $teamName,
                        "missing_on" => $userMissing
                    ];
                }
            }

            return response()->json([
                "success" => true,
                "message" => "Users who did not submit timesheet",
                "missing_dates" => $missingDates,
                "count" => count($userResult),
                "data" => $userResult
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Internal Server Error",
                "error" => $e->getMessage()
            ], 500);
        }
    }


    public function getMissingUserPerformaSheets(Request $request)
    {
        try {
            $user = User::find($request->user_id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if (!$user->created_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'User joining date not set'
                ], 400);
            }
            if ($request->start_date && $request->end_date) {
                $startDate = Carbon::parse($request->start_date);
                $endDate = Carbon::parse($request->end_date);
            } else {
                $startDate = Carbon::parse($user->created_at)->startOfDay();
                $endDate = Carbon::now()->startOfDay();
            }
            $allDates = collect();
            $current = $startDate->copy();

            while ($current->lte($endDate)) {
                if ($current->isWeekday()) {
                    $allDates->push($current->toDateString());
                }
                $current->addDay();
            }

            $submittedDates = PerformaSheet::where('user_id', $user->id)
                ->get()
                ->map(function ($sheet) {
                    $data = json_decode($sheet->data, true);
                    return isset($data['date']) ? $data['date'] : null;
                })
                ->filter()
                ->unique()
                ->values();

            $missingDates = $allDates->diff($submittedDates)->values();

            return response()->json([
                'success' => true,
                'message' => 'Missing timesheet dates fetched successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'created_date' => $user->created_at,
                ],
                'total_missing_days' => $missingDates->count(),
                'data' => $missingDates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // public function TeamWiseDailyWorkingHours(Request $request)
    // {
    //     try {
    //         $selectedDate = $request->date ? Carbon::parse($request->date) : Carbon::today();
    //         $startOfDay = $selectedDate->copy()->startOfDay();
    //         $endOfDay = $selectedDate->copy()->endOfDay();
    //         $dailyExpectedMinutes = (8 * 60) + 30;
    //         $leaveMinutesMap = [
    //             "Full Leave" => $dailyExpectedMinutes,
    //             "Multiple Days Leave" => $dailyExpectedMinutes,
    //             "Half Day" => intval($dailyExpectedMinutes / 2),
    //             "Short Leave" => 120
    //         ];

    //         $current_user = auth()->user();
    //         $currentTeamIds = $current_user->team_id;
    //         if ($current_user->role_id == 5 || $current_user->role_id == 6) {
    //             $teams = Team::whereIn('id', $currentTeamIds)
    //                 ->latest()
    //                 ->get();

    //         } elseif ($current_user->role_id == 7) {
    //             return response()->json([
    //                 "success" => false,
    //                 "message" => "You don't have permission",
    //                 "data" => []
    //             ]);
    //         } else {
    //             $teams = Team::latest()->get();
    //         }


    //         $finalData = [];
    //         $toTime = function ($minutes) {
    //             $h = floor($minutes / 60);
    //             $m = $minutes % 60;
    //             return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
    //         };
    //         foreach ($teams as $team) {

    //             $users = User::whereJsonContains('team_id', $team->id)
    //                 ->where('is_active', true)
    //                 ->whereIn('role_id', [7])
    //                 ->get();
    //             $teamUserCount = $users->count();
    //             $expectedTeamMinutes = $teamUserCount * $dailyExpectedMinutes;


    //             $leaveTeamMinutes = 0;
    //             $totalTeamLeaves = 0;
    //             $teamMembers = [];
    //             $baseMinutes = $dailyExpectedMinutes;

    //             foreach ($users as $user) {
    //                 $availability = "Available";
    //                 $leaveType = null;
    //                 $leaveMinutes = 0;
    //                 $expectedMinutes = $baseMinutes;
    //                 $actualMinutes = $baseMinutes;

    //                 $userLeaves = LeavePolicy::where('user_id', $user->id)
    //                     ->whereIn('leave_type', [
    //                         'Full Leave',
    //                         'Multiple Days Leave',
    //                         'Half Day',
    //                         'Short Leave'
    //                     ])
    //                     ->whereIn('status', ['Approved', 'Pending'])
    //                     ->get();

    //                 foreach ($userLeaves as $leave) {

    //                     $type = $leave->leave_type;
    //                     $leaveStart = Carbon::parse($leave->start_date)->startOfDay();
    //                     $leaveEnd = Carbon::parse($leave->end_date)->endOfDay();

    //                     if ($selectedDate->between($leaveStart, $leaveEnd)) {
    //                         $leaveType = $leave->leave_type;
    //                         $availability = "On Leave";
    //                         $leaveMinutes = $leaveMinutesMap[$leaveType];

    //                         switch ($leaveType) {

    //                             case 'Full Leave':
    //                             case 'Multiple Days Leave':
    //                                 $availability = "On Leave";
    //                                 $expectedMinutes = 0;
    //                                 $actualMinutes = 0;
    //                                 break;

    //                             case 'Half Day':
    //                                 $availability = "On Leave";
    //                                 $expectedMinutes = intval($baseMinutes / 2);
    //                                 $actualMinutes = intval($baseMinutes / 2);
    //                                 break;

    //                             case 'Short Leave':
    //                                 $availability = "On Leave";
    //                                 $expectedMinutes = $baseMinutes;
    //                                 $actualMinutes = $baseMinutes - $leaveMinutes;
    //                                 break;
    //                         }

    //                         $totalTeamLeaves += 1;
    //                         $leaveTeamMinutes += $leaveMinutesMap[$type];
    //                     }
    //                 }
    //                 $teamMembers[] = [
    //                     "user_id" => $user->id,
    //                     "name" => $user->name,
    //                     "leave_type" => $leaveType,
    //                     "availability" => $availability,
    //                     "leave_hours" => $toTime($leaveMinutes),
    //                     "expected_hours" => $toTime($expectedMinutes),
    //                     "actual_hours" => $toTime($actualMinutes)
    //                 ];
    //             }
    //             $totalTeamHoursMinutes = $expectedTeamMinutes - $leaveTeamMinutes;

    //             $finalData[] = [
    //                 "teamName" => $team->team_name ?? $team->name,
    //                 "totalTeamMembers" => $teamUserCount,
    //                 "expectedHours" => $toTime($expectedTeamMinutes),
    //                 "totalHours" => $toTime($totalTeamHoursMinutes),
    //                 "totalTeamLeaves" => $totalTeamLeaves,
    //                 "leaveHours" => $toTime($leaveTeamMinutes),
    //                 "selectedDate" => $selectedDate->format("Y-m-d"),
    //                 "teamMembers" => $teamMembers
    //             ];
    //         }

    //         return response()->json([
    //             "success" => true,
    //             "message" => "Team-wise daily working hours overview",
    //             "data" => $finalData
    //         ]);

    //     } catch (\Exception $e) {

    //         return response()->json([
    //             "success" => false,
    //             "message" => "Server Error",
    //             "error" => $e->getMessage(),
    //             "line" => $e->getLine()
    //         ], 500);
    //     }
    // }



    public function TeamWiseDailyWorkingHours(Request $request)
    {
        try {
            // -----------------------------
            // Date Handling
            // -----------------------------
            if ($request->start_date && $request->end_date) {
                $startDate = Carbon::parse($request->start_date)->startOfDay();
                $endDate = Carbon::parse($request->end_date)->endOfDay();
            } else {
                $startDate = Carbon::today()->startOfDay();
                $endDate = Carbon::today()->endOfDay();
            }

            $dailyExpectedMinutes = (8 * 60) + 30;

            $leaveMinutesMap = [
                "Full Leave" => $dailyExpectedMinutes,
                "Multiple Days Leave" => $dailyExpectedMinutes,
                "Half Day" => intval($dailyExpectedMinutes / 2),
                "Short Leave" => 120
            ];

            // -----------------------------
            // Auth & Teams
            // -----------------------------
            $current_user = auth()->user();
            $currentTeamIds = $current_user->team_id;

            if ($current_user->hasAnyRole([5, 6])) {
                $teams = Team::whereIn('id', $currentTeamIds)->latest()->get();
            } elseif ($current_user->hasRole(7)) {
                return response()->json([
                    "success" => false,
                    "message" => "You don't have permission",
                    "data" => []
                ]);
            } else {
                $teams = Team::latest()->get();
            }

            // -----------------------------
            // Helpers
            // -----------------------------
            $toTime = function ($minutes) {
                $h = floor($minutes / 60);
                $m = $minutes % 60;
                return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
            };

            $finalData = [];

            // -----------------------------
            // Initialize team containers
            // -----------------------------
            foreach ($teams as $team) {
                $finalData[$team->id] = [
                    "teamName" => $team->team_name ?? $team->name,
                    "totalTeamMembers" => 0,
                    "expectedMinutes" => 0,
                    "actualMinutes" => 0,
                    "leaveMinutes" => 0,
                    "totalTeamLeaves" => 0,
                    "teamMembers" => []
                ];
            }

            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {

                foreach ($teams as $team) {

                    $users = User::whereJsonContains('team_id', $team->id)
                        ->where('is_active', true)
                        ->where(function ($q) {
                            $q->orWhereJsonContains('role_id', 7);
                        })
                        ->get();

                    $finalData[$team->id]["totalTeamMembers"] = $users->count();

                    foreach ($users as $user) {

                        if (!isset($finalData[$team->id]["teamMembers"][$user->id])) {
                            $finalData[$team->id]["teamMembers"][$user->id] = [
                                "user_id" => $user->id,
                                "name" => $user->name,
                                "expectedMinutes" => 0,
                                "actualMinutes" => 0,
                                "leaveMinutes" => 0,
                                "availability" => "Available"
                            ];
                        }

                        $expectedMinutes = $dailyExpectedMinutes;
                        $leaveMinutes = 0;

                        $userLeaves = LeavePolicy::where('user_id', $user->id)
                            ->whereIn('leave_type', array_keys($leaveMinutesMap))
                            ->whereIn('status', ['Approved', 'Pending'])
                            ->get();

                        foreach ($userLeaves as $leave) {
                            $leaveStart = Carbon::parse($leave->start_date)->startOfDay();
                            $leaveEnd = Carbon::parse($leave->end_date)->endOfDay();

                            if ($date->between($leaveStart, $leaveEnd)) {

                                $leaveMinutes = $leaveMinutesMap[$leave->leave_type];
                                $finalData[$team->id]["totalTeamLeaves"]++;
                                $finalData[$team->id]["teamMembers"][$user->id]["availability"] = "On Leave";
                            }
                        }

                        // Merge totals
                        $finalData[$team->id]["expectedMinutes"] += $expectedMinutes;
                        $finalData[$team->id]["leaveMinutes"] += $leaveMinutes;
                        $finalData[$team->id]["actualMinutes"] += ($expectedMinutes - $leaveMinutes);

                        $finalData[$team->id]["teamMembers"][$user->id]["expectedMinutes"] += $expectedMinutes;
                        $finalData[$team->id]["teamMembers"][$user->id]["leaveMinutes"] += $leaveMinutes;
                        $finalData[$team->id]["teamMembers"][$user->id]["actualMinutes"] += ($expectedMinutes - $leaveMinutes);
                    }
                }
            }

            // -----------------------------
            // Format Output
            // -----------------------------
            $response = [];

            foreach ($finalData as $team) {
                $members = [];

                foreach ($team["teamMembers"] as $member) {
                    $members[] = [
                        "user_id" => $member["user_id"],
                        "name" => $member["name"],
                        "availability" => $member["availability"],
                        "expected_hours" => $toTime($member["expectedMinutes"]),
                        "actual_hours" => $toTime($member["actualMinutes"]),
                        "leave_hours" => $toTime($member["leaveMinutes"])
                    ];
                }

                $response[] = [
                    "teamName" => $team["teamName"],
                    "totalTeamMembers" => $team["totalTeamMembers"],
                    "expectedHours" => $toTime($team["expectedMinutes"]),
                    "totalHours" => $toTime($team["actualMinutes"]),
                    "leaveHours" => $toTime($team["leaveMinutes"]),
                    "totalTeamLeaves" => $team["totalTeamLeaves"],
                    "teamMembers" => $members
                ];
            }

            return response()->json([
                "success" => true,
                "message" => "Team-wise working hours",
                "data" => $response
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Server Error",
                "error" => $e->getMessage(),
                "line" => $e->getLine()
            ], 500);
        }
    }



    public function getUserDaterangePerformaSheets(Request $request)
    {
        $user = auth()->user();
        try {
            $weeklyTotals = [];
            $dailyExpectedMinutes = 510;
            $leaveMinutesMap = [
                'Full Leave' => $dailyExpectedMinutes,
                'Multiple Days Leave' => $dailyExpectedMinutes,
                'Half Day' => intval($dailyExpectedMinutes / 2),
                'Short Leave' => 120,
            ];

            $startDate = $request->query('start_date')
                ? Carbon::parse($request->query('start_date'))->startOfDay()
                : Carbon::today()->startOfMonth();

            $endDate = $request->query('end_date')
                ? Carbon::parse($request->query('end_date'))->endOfDay()
                : Carbon::today()->endOfMonth();

            if ($startDate->gt($endDate)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Start date cannot be greater than end date'
                ], 400);
            }

            $period = new \DatePeriod($startDate, \DateInterval::createFromDateString('1 day'), $endDate->copy()->addDay(false));

            $leaves = LeavePolicy::where('user_id', $user->id)
                ->whereIn('leave_type', array_keys($leaveMinutesMap))
                ->whereIn('status', ['Approved', 'Pending'])
                ->get();

            $sheets = PerformaSheet::with('user:id,name')
                ->where('user_id', $user->id)
                ->get()
                ->filter(function ($sheet) use ($startDate, $endDate) {
                    $data = json_decode($sheet->data, true);
                    if (!$data || !isset($data['date']))
                        return false;

                    $date = $data['date'];
                    return $date >= $startDate->toDateString() &&
                        $date <= $endDate->toDateString();
                })
                ->values();

            // Initialize weekly totals
            foreach ($period as $day) {
                $carbonDay = Carbon::instance($day);
                $weeklyTotals[$carbonDay->toDateString()] = [
                    'dayname' => $carbonDay->format('D'),
                    'availability' => 'Working',
                    'leave_type' => null,
                    'leave_hours' => '00:00',
                    'working_hours' => '00:00',
                    'totalHours' => '00:00',
                    'totalBillableHours' => '00:00',
                    'totalNonBillableHours' => '00:00',
                ];
            }

            $timeToMinutes = function ($time) {
                [$hours, $minutes] = explode(':', $time);
                return intval($hours) * 60 + intval($minutes);
            };

            $minutesToTime = function ($minutes) {
                $h = floor($minutes / 60);
                $m = $minutes % 60;
                return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT);
            };

            $totalsInMinutes = [];
            $billableInMinutes = [];
            $nonBillableInMinutes = [];

            foreach ($sheets as $sheet) {
                $data = json_decode($sheet->data, true);
                if (!$data || !isset($data['date'], $data['time'], $data['activity_type']))
                    continue;

                $date = $data['date'];
                $time = $data['time'];
                $activityType = strtolower($data['activity_type']); // to handle case variations
                $minutes = $timeToMinutes($time);

                // Total
                $totalsInMinutes[$date] = ($totalsInMinutes[$date] ?? 0) + $minutes;

                // Billable / Non-Billable
                if ($activityType === 'billable') {
                    $billableInMinutes[$date] = ($billableInMinutes[$date] ?? 0) + $minutes;
                } else {
                    $nonBillableInMinutes[$date] = ($nonBillableInMinutes[$date] ?? 0) + $minutes;
                }
            }

            // Assign to weekly totals
            // foreach ($weeklyTotals as $date => &$totals) {
            //     $totals['totalHours'] = isset($totalsInMinutes[$date]) ? $minutesToTime($totalsInMinutes[$date]) : '00:00';
            //     $totals['totalBillableHours'] = isset($billableInMinutes[$date]) ? $minutesToTime($billableInMinutes[$date]) : '00:00';
            //     $totals['totalNonBillableHours'] = isset($nonBillableInMinutes[$date]) ? $minutesToTime($nonBillableInMinutes[$date]) : '00:00';
            // }

            foreach ($weeklyTotals as $date => &$dayData) {

                $currentDate = Carbon::parse($date);

                // Weekend
                if ($currentDate->isWeekend()) {
                    $dayData['availability'] = 'Weekend';
                    continue;
                }

                foreach ($leaves as $leave) {

                    $leaveStart = Carbon::parse($leave->start_date)->startOfDay();
                    $leaveEnd = Carbon::parse($leave->end_date)->endOfDay();

                    if ($currentDate->between($leaveStart, $leaveEnd)) {

                        $leaveType = $leave->leave_type;
                        $leaveMinutes = $leaveMinutesMap[$leaveType] ?? 0;

                        $workedMinutes = $totalsInMinutes[$date] ?? 0;

                        switch ($leaveType) {

                            case 'Full Leave':
                            case 'Multiple Days Leave':
                                $dayData['availability'] = 'On Leave';
                                $dayData['working_hours'] = '00:00';
                                break;

                            case 'Half Day':
                                $dayData['availability'] = 'On Leave';
                                $dayData['working_hours'] = $minutesToTime($workedMinutes);
                                break;

                            case 'Short Leave':
                                $dayData['availability'] = 'On Leave';
                                $dayData['working_hours'] = $minutesToTime($workedMinutes);
                                break;
                        }

                        $dayData['leave_type'] = $leaveType;
                        $dayData['leave_hours'] = $minutesToTime($leaveMinutes);

                        break;
                    }
                }
                if ($dayData['availability'] === 'Working') {
                    $dayData['working_hours'] = isset($totalsInMinutes[$date])
                        ? $minutesToTime($totalsInMinutes[$date])
                        : '00:00';
                }
            }


            return response()->json([
                'success' => true,
                'message' => 'Monthly Performa Sheets fetched successfully',
                'data' => $weeklyTotals
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function applyToFillPerformaSheets(Request $request)
    {
        $submitting_user = auth()->user();
        $validatedData = $request->validate([
            'apply_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'performa_sheet' => 'nullable|exists:performa_sheets,id',
        ]);
        $application = ApplicationPerforma::create([
            'user_id' => $submitting_user->id,
            'performa_sheet' => $validatedData['performa_sheet'],
            'status' => 'pending',
            'apply_date' => $validatedData['apply_date'],
            'approval_date' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application For Performa submitted successfully',
            'data' => [
                'id' => $application->id,
                'user_id' => $application->user_id,
                'performa_sheet' => $application->performa_sheet,
                'status' => $application->status,
                'apply_date' => $application->apply_date
            ]
        ], 201);
    }
    public function approveApplicationPerformaSheets(Request $request, $id)
    {
        $approver = auth()->user();
        if (!$approver->hasRole(4)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to approve this application'
            ], 403);
        }

        $application = ApplicationPerforma::find($id);

        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found'
            ], 404);
        }

        if ($application->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Application already approved'
            ], 422);
        }

        $application->update([
            'status' => 'approved',
            'approval_date' => now()->toDateString()
        ]);
        /**update performa with status pending */
        $performa_id = $application->performa_sheet;
        $performa_sheet_data = PerformaSheet::where('id', $performa_id)
            ->where('user_id', $application->user_id)
            ->first();

        if (in_array(strtolower($performa_sheet_data->status), ['standup', 'backdated'])) {
            $performa_sheet_data->status = 'approved';
        }
        $performa_sheet_data->save();


        return response()->json([
            'success' => true,
            'message' => 'Application to fill performa approved successfully',
            'data' => [
                'id' => $application->id,
                'user_id' => $application->user_id,
                'performa_sheet' => $application->performa_sheet,
                'status' => $application->status,
                'apply_date' => $application->apply_date,
                'approval_date' => $application->approval_date
            ]
        ]);
    }
    public function rejectApplicationPerformaSheets(Request $request, $id)
    {
        $application = ApplicationPerforma::find($id);
        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found'
            ], 404);
        }

        $application->update([
            'status' => 'rejected',
            'approval_date' => now()->toDateString()
        ]);

        /**update performa with status pending */
        $performa_id = $application->performa_sheet;
        $performa_sheet_data = PerformaSheet::where('id', $performa_id)
            ->where('user_id', $application->user_id)
            ->first();
        $performa_sheet_data->delete();

        return response()->json([
            'success' => true,
            'message' => 'Performa application rejected',
            'data' => $application
        ]);
    }

    public function getAllPerformaApplications(Request $request)
    {
        try {
            $applications = ApplicationPerforma::with('user:id,name')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Performa applications fetched successfully',
                'data' => $applications
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    private function minutesToHours($minutes)
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }

    private function timeToMinutesforgetUserPerformaData($time)
    {
        if (!$time || !str_contains($time, ':')) {
            return 0;
        }

        [$h, $m] = array_pad(explode(':', $time), 2, 0);
        return ((int) $h * 60) + (int) $m;
    }

    public function getUserPerformaData(Request $request)
    {
        if (!$request->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'user id is required'
            ], 404);
        }

        try {

            $startDate = $request->start_date
                ? Carbon::parse($request->start_date)
                : Carbon::today();

            $endDate = $request->end_date
                ? Carbon::parse($request->end_date)
                : Carbon::today();

            $performaSheets = DB::table('performa_sheets')
                ->where('user_id', $request->user_id)
                ->where('status', 'approved')
                ->get();

            $activityTotals = [
                'Billable' => 0,
                'In-House' => 0,
                'No Work' => 0,
                'Offline' => 0,
            ];

            foreach ($performaSheets as $row) {

                $decoded = json_decode($row->data, true);
                if (is_string($decoded)) {
                    $decoded = json_decode($decoded, true);
                }

                $entries = isset($decoded[0]) ? $decoded : [$decoded];

                foreach ($entries as $entry) {

                    if (!isset($entry['activity_type'], $entry['time'], $entry['date'])) {
                        continue;
                    }

                    $entryDate = Carbon::parse($entry['date']);

                    if ($entryDate->lt($startDate) || $entryDate->gt($endDate)) {
                        continue;
                    }

                    if (!isset($activityTotals[$entry['activity_type']])) {
                        continue;
                    }

                    // [$h, $m] = explode(':', $entry['time']);
                    // $activityTotals[$entry['activity_type']] += ((int) $h * 60) + (int) $m;
                    $activityTotals[$entry['activity_type']] += $this->timeToMinutesforgetUserPerformaData($entry['time']);

                }
            }

            $leaves = LeavePolicy::where('user_id', $request->user_id)
                ->where('status', 'Approved')
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])
                        ->orWhereBetween('end_date', [$startDate, $endDate]);
                })
                ->get();

            $totalLeaveMinutes = 0;

            foreach ($leaves as $leave) {

                switch ($leave->leave_type) {

                    case 'Full Leave':
                        $totalLeaveMinutes += 510;
                        break;

                    case 'Half Day':
                        $totalLeaveMinutes += 255;
                        break;

                    case 'Short Leave':
                        if ($leave->hours) {
                            $totalLeaveMinutes += $this->timeToMinutesforgetUserPerformaData($leave->hours);
                        }
                        break;

                    case 'Multiple Days Leave':
                        $days = Carbon::parse($leave->start_date)
                            ->diffInDays(Carbon::parse($leave->end_date)) + 1;
                        $totalLeaveMinutes += $days * 510;
                        break;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Performa & Leave summary fetched successfully',
                'data' => [
                    'activities' => collect($activityTotals)->map(function ($minutes) {
                        return $this->minutesToHours($minutes);
                    }),
                    'leave_hours' => $this->minutesToHours($totalLeaveMinutes),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUsersOfflineHours(Request $request)
    {
        try {

            $startDate = $request->start_date
                ? Carbon::parse($request->start_date)->startOfDay()
                : Carbon::today()->startOfDay();

            $endDate = $request->end_date
                ? Carbon::parse($request->end_date)->endOfDay()
                : Carbon::today()->endOfDay();

            $performaSheets = DB::table('performa_sheets')
                ->where('status', 'approved')
                ->get();

            $data = [];

            foreach ($performaSheets as $row) {

                $decoded = json_decode($row->data, true);
                if (is_string($decoded)) {
                    $decoded = json_decode($decoded, true);
                }

                $entries = isset($decoded[0]) ? $decoded : [$decoded];

                foreach ($entries as $entry) {

                    if (
                        empty($entry['offline_hours']) ||
                        empty($entry['date']) ||
                        empty($entry['project_id'])
                    ) {
                        continue;
                    }

                    $entryDate = Carbon::parse($entry['date']);
                    if ($entryDate->lt($startDate) || $entryDate->gt($endDate)) {
                        continue;
                    }

                    [$h, $m] = explode(':', $entry['offline_hours']);
                    $minutes = ((int) $h * 60) + (int) $m;

                    $data[$row->user_id][$entry['project_id']] =
                        ($data[$row->user_id][$entry['project_id']] ?? 0) + $minutes;
                }
            }

            if (empty($data)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $users = User::whereIn('id', array_keys($data))->where("is_active", 1)
                ->pluck('name', 'id');

            $projectIds = collect($data)
                ->flatMap(fn($projects) => array_keys($projects))
                ->unique()
                ->values();

            $projects = DB::table('projects_master')
                ->whereIn('id', $projectIds)
                ->pluck('project_name', 'id');

            $projectRelations = DB::table('project_relations')
                ->whereIn('project_id', $projectIds)
                ->pluck('tracking_id', 'project_id');

            $trackingIds = $projectRelations
                ->filter()
                ->unique()
                ->values();

            $accounts = DB::table('project_accounts')
                ->whereIn('id', $trackingIds)
                ->pluck('account_name', 'id');

            $response = [];

            foreach ($data as $userId => $projectsData) {

                $userTotalMinutes = array_sum($projectsData);
                $projectArray = [];

                foreach ($projectsData as $projectId => $minutes) {

                    $trackingId = $projectRelations[$projectId] ?? null;

                    $projectArray[] = [
                        'project_name' => $projects[$projectId] ?? '',
                        'traking_id' => $accounts[$trackingId] ?? '',
                        'total_offline_hours' => $this->minutesToHours($minutes)
                    ];
                }

                $response[] = [
                    'user_id' => $userId,
                    'user_name' => $users[$userId] ?? '',
                    'total_offline_hours' => $this->minutesToHours($userTotalMinutes),
                    'projects' => $projectArray
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $response
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
