<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Project;
use Illuminate\Http\Request;
use App\Http\Resources\TaskResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
public function AddTasks(Request $request)
{
    try {
        $user = Auth::user();

        // Validation with custom error messages
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:To do,In Progress,Completed,Cancel',
            'project_id' => 'required|exists:projects,id',
            'hours' => 'nullable|numeric|min:0',
            'deadline' => 'nullable|date|after_or_equal:today',
            'start_date' => 'nullable|date'
        ], [
            'title.required' => 'Task title is required.',
            'status.required' => 'Task status is required.',
            'status.in' => 'Status must be one of: To do, In Progress, Completed, Cancel.',
            'project_id.required' => 'Project ID is required.',
            'project_id.exists' => 'Selected project does not exist.',
            'hours.numeric' => 'Hours must be a valid number.',
            'hours.min' => 'Hours cannot be negative.',
            'deadline.after_or_equal' => 'Deadline must be today or a future date.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validatedData = $validator->validated();

        // Check for duplicate task
        $duplicateTask = Task::where('title', $validatedData['title'])
            ->where('project_id', $validatedData['project_id'])
            ->where('status', $validatedData['status'])
            ->first();

        if ($duplicateTask) {
            return response()->json([
                'success' => false,
                'message' => 'A task with the same title already exists in this project.'
            ], 409);
        }

        // Get project (already validated to exist)
        $project = Project::find($validatedData['project_id']);

        // Calculate updated hours
        $currentHours = $project->total_hours ?? 0;
        $currentRemaining = $project->remaining_hours ?? 0;
        $newTaskHours = $validatedData['hours'] ?? 0;
        $newTotalHours = $currentHours + $newTaskHours;
        $newRemaining = $currentRemaining + $newTaskHours;

        // Create task
        $task = Task::create([
            'title' => $validatedData['title'],
            'description' => $validatedData['description'] ?? null,
            'status' => $validatedData['status'],
            'project_id' => $validatedData['project_id'],
            'project_manager_id' => $user->id,
            'hours' => $validatedData['hours'] ?? null,
            'deadline' => $validatedData['deadline'] ?? null,
            'start_date' => $validatedData['start_date'] ?? null
        ]);

        // Get highest deadline from all tasks in this project
        $highestDeadline = Task::where('project_id', $validatedData['project_id'])
            ->whereNotNull('deadline')
            ->max('deadline');

        // Update project
        $project->update([
            'total_hours' => $newTotalHours,
            'remaining_hours' => $newRemaining,
            'deadline' => $highestDeadline
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Task created successfully and project updated.',
            'data' => [
                'task' => $task,
                'project_updates' => [
                    'id' => $project->id,
                    'name' => $project->project_name,
                    'total_hours' => $newTotalHours,
                    'remaining_hours' => $newRemaining,
                    'deadline' => $highestDeadline
                ]
            ]
        ], 201);

    } catch (\Illuminate\Database\QueryException $e) {
        \Log::error('Database error in AddTasks: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Database error occurred while creating task.',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
        ], 500);

    } catch (\Exception $e) {
        \Log::error('Error in AddTasks: ' . $e->getMessage());
        \Log::error('Stack trace: ' . $e->getTraceAsString());

        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred.',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
        ], 500);
    }
}


public function getAllTaskofProjectById($id)
{
    $project = Project::find($id);

    if (!$project) {
        return response()->json([
            'success' => false,
            'message' => 'Project not found.'
        ], 404);
    }

    $projectManagers = json_decode($project->project_manager_id, true);
    
    $tasks = Task::where('project_id', $id)
        ->with('projectManager:id,name') 
        ->get();

    $totalTaskHours = $tasks->sum('hours');

    $formattedTasks = $tasks->map(function ($task) {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'hours' => $task->hours,
            'deadline' => $task->deadline,
            'start_date' => $task->start_date,
            'project_manager' => $task->projectManager ? [
                'id' => $task->projectManager->id,
                'name' => $task->projectManager->name
            ] : null
        ];
    });

    return response()->json([
        'success' => true,
        'message' => 'Project fetched successfully.',
        'data' => [
            'id' => $project->id,
            'project_name' => $project->project_name,
            'project_type' => $project->project_type,
            'project_status' => $project->project_status,
            'deadline' => $project->deadline,
            'total_hours' => $project->total_hours,
            'total_working_hours' => $project->total_working_hours,
            'total_task_hours' => $totalTaskHours, 
            'project_managers' => $projectManagers, 
            'tasks' => $formattedTasks, 
            'created_at' => $project->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $project->updated_at->format('Y-m-d H:i:s')
        ]
    ]);
}

public function getEmployeTasksbyProject(Request $request)
{
    try {
        $user = Auth::user(); 
        $userId = $user->id;

        $validatedData = $request->validate([
            'project_id' => 'required|exists:projects,id'
        ]);

        $projectId = $validatedData['project_id'];

        $projectManagerId = DB::table('project_user')
            ->where('project_id', $projectId)
            ->where('user_id', $userId)
            ->value('project_manager_id'); 

        if (!$projectManagerId) {
            return response()->json([
                'success' => false,
                'message' => 'No project manager found for this project and user.'
            ], 403);
        }

        $projectManager = User::find($projectManagerId);

        if (!$projectManager) {
            return response()->json([
                'success' => false,
                'message' => 'Project Manager not found in users table.',
                'project_manager_id' => $projectManagerId
            ], 404);
        }

        $project = Project::find($projectId);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.'
            ], 404);
        }

        $tasks = Task::where('project_id', $projectId)
            ->where('project_manager_id', $projectManagerId)
            ->get();

        if ($tasks->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No tasks found for this project and project manager.',
                'project' => [
                    'id' => $project->id,
                    'name' => $project->project_name,
                    'deadline' => $project->deadline,
                    'total_hours' => $project->total_hours,
                    'total_working_hours' => $project->total_working_hours
                ],
                'project_manager' => [
                    'id' => $projectManagerId,
                    'name' => $projectManager->name
                ],
                'data' => []
            ]);
        }

        $formattedTasks = $tasks->map(function ($task) {
            return [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'hours' => $task->hours,
                'deadline' => $task->deadline,
                'start_date' => $task->start_date 
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Tasks fetched successfully.',
            'project' => [
                'id' => $project->id,
                'name' => $project->project_name,
                'deadline' => $project->deadline,
                'total_hours' => $project->total_hours,
                'total_working_hours' => $project->total_working_hours
            ],
            'project_manager' => [
                'id' => $projectManagerId,
                'name' => $projectManager->name
            ],
            'data' => $formattedTasks
        ]);

    } catch (\Exception $e) {
        Log::error('Error fetching tasks: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Internal Server Error',
            'error' => $e->getMessage()
        ], 500);
    }
}

	public function ApproveTaskofProject(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'id' => 'required|exists:tasks,id',
                'status' => 'required|in:To do,In Progress,Completed,Cancel'
            ]);

            $task = Task::find($validatedData['id']);

            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found.'
                ], 404);
            }

            $projectManagerId = Auth::user()->id;

            /*if ($task->project_manager_id != $projectManagerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to approve this taskxxxx.'
                ], 403);
            }*/

            $task->status = $validatedData['status'];
            $task->save();

            return response()->json([
                'success' => true,
                'message' => 'Task status updated successfully.',
                'data' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'updated_by' => [
                        'id' => $projectManagerId,
                        'name' => Auth::user()->name
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating task status: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

public function EditTasks(Request $request, $id)
{
    try {
        $validatedData = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'hours' => 'sometimes|nullable|integer|min:0',
            'deadline' => 'sometimes|nullable|date',
            'start_date' => 'sometimes|nullable|date' // ✅ added optional start_date
        ]);

        $task = Task::find($id);
        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found.'
            ], 404);
        }

        $project = Project::find($task->project_id);
        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.'
            ], 404);
        }

        // Handle null values properly
        if (array_key_exists('hours', $validatedData) && (int)$validatedData['hours'] === 0) {
            $validatedData['hours'] = null;
        }

        if (array_key_exists('deadline', $validatedData) && empty($validatedData['deadline'])) {
            $validatedData['deadline'] = null;
        }

        if (array_key_exists('start_date', $validatedData) && empty($validatedData['start_date'])) {
            $validatedData['start_date'] = null; // ✅ handle empty start_date
        }

        // Handle hours update and recalculate total hours
        if (array_key_exists('hours', $validatedData)) {
            $previousHours = $task->hours ?? 0;
            $newHours = $validatedData['hours'] ?? 0;

            if ($newHours !== null) {
                $newTotalHours = max(0, ($project->total_hours - $previousHours) + $newHours);
                $project->update(['total_hours' => $newTotalHours]);
                $task->hours = $newHours;
            } else {
                $task->hours = null;
            }
        }

        // Update other fields including start_date
        $task->update($validatedData);

        // Update project deadline based on all tasks
        $highestDeadline = Task::where('project_id', $task->project_id)
            ->whereNotNull('deadline')
            ->max('deadline');

        if ($highestDeadline) {
            $project->update(['deadline' => $highestDeadline]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully, project details also updated.',
            'project' => [
                'id' => $project->id,
                'name' => $project->project_name,
                'updated_total_hours' => $project->total_hours,
                'updated_deadline' => $highestDeadline
            ],
            'task' => $task
        ]);

    } catch (\Exception $e) {
        \Log::error('Error updating task: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Internal Server Error',
            'error' => $e->getMessage()
        ], 500);
    }
}



public function DeleteTasks(Request $request, $id)
{
    try {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found.'
            ], 404);
        }

        $project = Project::find($task->project_id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.'
            ], 404);
        }

        $previousHours = $task->hours ?? 0;
        $newTotalHours = max(0, $project->total_hours - $previousHours);
        $newRemainingHours = max(0, $project->remaining_hours - $previousHours);

        $project->update([
            'total_hours' => $newTotalHours,
            'remaining_hours' => $newRemainingHours
        ]);

        $task->delete();

        $highestDeadline = Task::where('project_id', $task->project_id)->max('deadline');

        $project->update(['deadline' => $highestDeadline]);

        return response()->json([
            'success' => true,
            'message' => 'Task deleted successfully and project details updated.',
            'project' => [
                'id' => $project->id,
                'name' => $project->project_name,
                'updated_total_hours' => $project->total_hours,
                'updated_remaining_hours' => $project->remaining_hours,
                'updated_deadline' => $highestDeadline
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Error deleting task: ' . $e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Internal Server Error',
            'error' => $e->getMessage()
        ], 500);
    }
}
}
