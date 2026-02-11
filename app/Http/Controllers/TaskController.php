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
use App\Models\ProjectMaster;
use App\Services\ActivityService;

class TaskController extends Controller
{
    public function AddTasks(Request $request)
    {
        try {
            $user = Auth::user();

            $validatedData = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'status' => 'required|in:To do,In Progress,Completed,Cancel',
                'project_id' => 'required|exists:projects_master,id',
                'hours' => 'nullable|numeric',
                'deadline' => 'nullable|date',
                'start_date' => 'nullable|date', // start_date ka validation
            ]);

            // Duplicate check SE start_date hata diya
            $duplicateTask = Task::where('title', $validatedData['title'])
                ->where('description', $validatedData['description'] ?? null)
                ->where('status', $validatedData['status'])
                ->where('project_id', $validatedData['project_id'])
                ->where('hours', $validatedData['hours'] ?? null)
                ->where('deadline', $validatedData['deadline'] ?? null)
                ->first();

            if ($duplicateTask) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task is already created with the same data.'
                ], 404);
            }

            $project = ProjectMaster::find($validatedData['project_id']);
            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found.'
                ], 404);
            }

            $currentHours = $project->project_hours ?? 0;
            if ($currentHours > 0) {
                $currentRemaining = $project->project_hours - $project->project_used_hours;
            }
            $newTaskHours = $validatedData['hours'] ?? 0;
            $newTotalHours = $currentHours + $newTaskHours;
            $newRemaining = $currentRemaining + $newTaskHours;

            $task = Task::create([
                'title' => $validatedData['title'],
                'description' => $validatedData['description'] ?? null,
                'status' => $validatedData['status'],
                'project_id' => $validatedData['project_id'],
                'project_manager_id' => $user->id,
                'hours' => $validatedData['hours'] ?? null,
                'deadline' => $validatedData['deadline'] ?? null,
                'start_date' => $validatedData['start_date'] ?? null // start_date include
            ]);

            $highestDeadline = Task::where('project_id', $validatedData['project_id'])
                ->whereNotNull('deadline')
                ->max('deadline');

            $project->update([
                'project_hours' => $newTotalHours,
                //commented for new approach
                // 'remaining_hours' => $newRemaining,
                'deadline' => $highestDeadline
            ]);

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Task created by ' . auth()->user()->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully and project updated.',
                'project' => [
                    'id' => $project->id,
                    'name' => $project->project_name,
                    'updated_total_hours' => $newTotalHours,
                    // 'updated_remaining_hours' => $newRemaining,
                    'updated_deadline' => $highestDeadline
                ],
                'task' => $task
            ]);

        } catch (\Exception $e) {
            \Log::error('Error adding task: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
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
                'used_hours' => $project->used_hours,
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

            $projectManager = User::where('id', $projectManagerId)
                ->where('is_active', 1)
                ->first();

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
                        'total_working_hours' => $project->total_working_hours,
                        'project_type' => $project->project_type,
                        'project_status' => $project->project_status
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
                    'total_working_hours' => $project->total_working_hours,
                    'project_type' => $project->project_type,
                    'project_status' => $project->project_status
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

            ActivityService::log([
                'project_id' => $task->project_id,
                'type' => 'activity',
                'description' => 'Task status updated by ' . auth()->user()->name,
            ]);
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
                'start_date' => 'sometimes|nullable|date'
            ]);

            $task = Task::find($id);
            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found.'
                ], 404);
            }

            $project = ProjectMaster::find($task->project_id);
            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found.'
                ], 404);
            }

            if (array_key_exists('hours', $validatedData) && (int) $validatedData['hours'] === 0) {
                $validatedData['hours'] = null;
            }

            if (array_key_exists('deadline', $validatedData) && empty($validatedData['deadline'])) {
                $validatedData['deadline'] = null;
            }

            if (array_key_exists('start_date', $validatedData) && empty($validatedData['start_date'])) {
                $validatedData['start_date'] = null;
            }

            if (array_key_exists('hours', $validatedData)) {
                $previousHours = $task->hours ?? 0;
                $newHours = $validatedData['hours'] ?? 0;

                if ($newHours !== null) {
                    $newTotalHours = max(0, ($project->project_hours - $previousHours) + $newHours);
                    $project->update(['project_hours' => $newTotalHours]);
                    $task->hours = $newHours;
                } else {
                    $task->hours = null;
                }
            }

            $task->update($validatedData);

            $highestDeadline = Task::where('project_id', $task->project_id)
                ->whereNotNull('deadline')
                ->max('deadline');

            if ($highestDeadline) {
                $project->update(['deadline' => $highestDeadline]);
            }

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Task updated by ' . auth()->user()->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully, project details also updated.',
                'project' => [
                    'id' => $project->id,
                    'name' => $project->project_name,
                    'updated_total_hours' => $project->project_hours,
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

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Task Deleted by ' . auth()->user()->name,
            ]);

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
    public function AddTasksToProjectMaster(Request $request)
    {
        try {
            $user = Auth::user();

            $validatedData = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'status' => 'required|in:To do,In Progress,Completed,Cancel',
                'project_id' => 'required|exists:projects_master,id',
                'hours' => 'nullable|numeric|min:0',
                'deadline' => 'nullable|date',
                'start_date' => 'nullable|date',
            ]);


            $duplicateTask = Task::where('title', $validatedData['title'])
                ->where('project_id', $validatedData['project_id'])
                ->first();

            if ($duplicateTask) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task already exists for this project.'
                ], 409);
            }


            $project = ProjectMaster::find($validatedData['project_id']);

            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project master not found.'
                ], 404);
            }


            $currentTotalHours = (float) ($project->project_hours ?? 0);
            $currentUsedHours = (float) ($project->project_used_hours ?? 0);
            $taskHours = (float) ($validatedData['hours'] ?? 0);

            $newUsedHours = $currentUsedHours + $taskHours;


            $task = Task::create([
                'title' => $validatedData['title'],
                'description' => $validatedData['description'] ?? null,
                'status' => $validatedData['status'],
                'project_id' => $validatedData['project_id'],
                'hours' => $taskHours,
                'deadline' => $validatedData['deadline'] ?? null,
                'start_date' => $validatedData['start_date'] ?? null,
            ]);

            $project->update([
                'project_used_hours' => $newUsedHours,
            ]);

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Task added to by ' . $user->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully.',
                'project_master' => [
                    'id' => $project->id,
                    'project_name' => $project->project_name,
                    'total_hours' => $currentTotalHours,
                    'used_hours' => $newUsedHours,
                ],
                'task' => $task
            ], 201);

        } catch (\Exception $e) {
            \Log::error('ProjectMaster Task Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getAllTaskOfProjectMasterById($id)
    {
        $project = ProjectMaster::find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project master not found.'
            ], 404);
        }

        $tasks = Task::where('project_id', $id)->get();

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
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Project master fetched successfully.',
            'data' => [
                'id' => $project->id,
                'project_name' => $project->project_name,
                'project_tracking' => $project->project_tracking,
                'project_status' => $project->project_status,
                'project_description' => $project->project_description,
                'project_budget' => $project->project_budget,
                'project_hours' => $project->project_hours,
                'project_used_hours' => $project->project_used_hours,
                'total_task_hours' => $totalTaskHours,
                'tasks' => $formattedTasks,
                'created_at' => $project->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $project->updated_at->format('Y-m-d H:i:s'),
            ]
        ]);
    }
    public function EditTasksForProjectMaster(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'sometimes|nullable|string',
                'hours' => 'sometimes|nullable|numeric|min:0',
                'deadline' => 'sometimes|nullable|date',
                'start_date' => 'sometimes|nullable|date',
                'status' => 'sometimes|in:To do,In Progress,Completed,Cancel',
            ]);

            $task = Task::find($id);
            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found.'
                ], 404);
            }

            $project = ProjectMaster::find($task->project_id);
            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project master not found.'
                ], 404);
            }

            if (array_key_exists('hours', $validatedData) && (int) $validatedData['hours'] === 0) {
                $validatedData['hours'] = null;
            }

            if (array_key_exists('deadline', $validatedData) && empty($validatedData['deadline'])) {
                $validatedData['deadline'] = null;
            }

            if (array_key_exists('start_date', $validatedData) && empty($validatedData['start_date'])) {
                $validatedData['start_date'] = null;
            }

            if (array_key_exists('hours', $validatedData)) {

                $previousHours = $task->hours ?? 0;
                $newHours = $validatedData['hours'] ?? 0;

                if ($newHours !== null) {
                    $newUsedHours = max(
                        0,
                        ($project->project_used_hours - $previousHours) + $newHours
                    );

                    $project->update([
                        'project_used_hours' => $newUsedHours
                    ]);

                    $task->hours = $newHours;
                } else {
                    $task->hours = null;
                }
            }

            $task->update($validatedData);

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Task updated by ' . auth()->user()->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully.',
                'project_master' => [
                    'id' => $project->id,
                    'project_name' => $project->project_name,
                    'project_used_hours' => $project->project_used_hours,
                ],
                'task' => $task
            ]);

        } catch (\Exception $e) {
            \Log::error('ProjectMaster Task Update Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function DeleteTasksForProjectMaster(Request $request, $id)
    {
        try {

            $task = Task::find($id);
            if (!$task) {
                return response()->json([
                    'success' => false,
                    'message' => 'Task not found.'
                ], 404);
            }

            $project = ProjectMaster::find($task->project_id);
            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project master not found.'
                ], 404);
            }

            $previousHours = $task->hours ?? 0;

            $newUsedHours = max(
                0,
                ($project->project_used_hours ?? 0) - $previousHours
            );

            $project->update([
                'project_used_hours' => $newUsedHours
            ]);

            $task->delete();

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Task deleted by ' . auth()->user()->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully.',
                'project_master' => [
                    'id' => $project->id,
                    'project_name' => $project->project_name,
                    'project_used_hours' => $project->project_used_hours,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('ProjectMaster Task Delete Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
