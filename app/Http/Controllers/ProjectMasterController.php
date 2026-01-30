<?php

namespace App\Http\Controllers;

use App\Http\Helpers\ApiResponse;
use App\Models\ProjectMaster;
use App\Models\CommunicationType;
use App\Http\Resources\ProjectMasterResource;
use App\Http\Resources\ProjectRelationResource;
use App\Mail\ProjectAssignedMail;
use App\Mail\ProjectAssignedToTLMail;
use App\Models\ProjectRelation;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\ProjectActivityAndComment;
use App\Services\ActivityService;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use League\Config\Exception\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Models\Task;
use App\Models\TagsActivity;
use App\Models\PerformaSheet;
use App\Models\ClientMaster;
use App\Models\Department;
use App\Models\LeavePolicy;
use App\Models\Team;
use Carbon\Carbon;

use function Symfony\Component\String\s;

class ProjectMasterController extends Controller
{
    public function index()
    {
        $currentUser = auth()->user();

        if ($currentUser->hasAnyRole([1, 2, 3, 4])) {

            $projects = ProjectMaster::with(['relation'])
                ->get();
        } else if (in_array(2, $currentUser->team_id)) {
            $projects = ProjectMaster::with(['relation'])
                ->whereHas('relation', function ($q) use ($currentUser) {
                    $q->where('sales_person_id', $currentUser->id);
                })
                ->get();
        } else {
            $projects = ProjectMaster::with(['relation'])
                ->get()
                ->filter(function (ProjectMaster $project) use ($currentUser) {

                    if (!$project->relation) {
                        return false;
                    }

                    $assignees = $project->relation->assignees ?? [];

                    if (is_array($assignees)) {
                    } elseif (is_numeric($assignees)) {
                        $assignees = [(int) $assignees];
                    } elseif (is_string($assignees)) {
                        $decoded = json_decode($assignees, true);
                        $assignees = is_array($decoded) ? $decoded : [];
                    } else {
                        $assignees = [];
                    }

                    return in_array($currentUser->id, $assignees, true);
                })
                ->values();
        }

        $data = $projects->map(function (ProjectMaster $project) {

            $relation = $project->relation;

            $attachments = ProjectActivityAndComment::where('project_id', $project->id)
                ->where('type', 'attachment')
                ->pluck('attachments');

            return [
                'project' => new ProjectMasterResource($project),
                'relation' => $relation
                    ? new ProjectRelationResource($relation)
                    : null,
                'attachment' => $attachments,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_name' => 'required|string|max:255',
            'client_id' => 'required|integer|exists:clients_master,id',
            'communication_id' => 'required',
            'assignees' => 'required',
            'source_id' => 'required|integer|exists:project_source,id',
            'account_id' => 'required|integer|exists:project_accounts,id',
            'sales_person_id' => 'nullable|integer|exists:users,id',
            'project_estimation_by' => 'nullable|integer|exists:users,id',
            'project_call_by' => 'nullable|integer|exists:users,id',
            'project_tracking' => 'required|integer',
            'tracking_id' => 'nullable|integer',
            'project_status' => 'nullable|string',
            'project_description' => 'nullable|string',
            'project_budget' => 'nullable|string',
            'project_hours' => 'nullable|string',
            'project_tag_activity' => 'required|integer',
            'project_used_hours' => 'nullable|string',
            'project_used_budget' => 'nullable|string',
            'offline_hours' => 'nullable|integer',
        ], [
            'client_id.exists' => 'Client does not exist',
            'source_id.exists' => 'Source does not exist',
            'account_id.exists' => 'Account does not exist',
            'sales_person_id.exists' => 'Sales person does not exist',
            'project_estimation_by.exists' => 'Project Estimation by User does not exist',
            'project_call_by.exists' => 'Project Call Taken by User does not exist',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 200);
        }

        $communication_id = $request->communication_id;

        if (is_string($communication_id)) {
            $communication_id = array_filter(
                array_map('intval', explode(',', $communication_id))
            );
        }

        if (!is_array($communication_id) || empty($communication_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid communication format'
            ], 200);
        }

        $existingIds = CommunicationType::whereIn('id', $communication_id)
            ->pluck('id')
            ->toArray();

        if (count($existingIds) !== count($communication_id)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more communication types do not exist'
            ], 200);
        }

        $assignees = $request->assignees;

        if (is_string($assignees)) {
            $assignees = array_filter(
                array_map('intval', explode(',', $assignees))
            );
        }

        if (!is_array($assignees) || empty($assignees)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid assignees format'
            ], 200);
        }

        $existingAssignees = User::whereIn('id', $assignees)->where('is_active', 1)
            ->pluck('id')
            ->toArray();

        if (count($existingAssignees) !== count($assignees)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more assignees not exist'
            ], 200);
        }


        try {
            DB::beginTransaction();

            $project = ProjectMaster::create([
                'project_name' => $request->project_name,
                'project_tracking' => $request->project_tracking,
                'project_status' => $request->project_status,
                'project_description' => $request->project_description,
                'project_budget' => $request->project_budget,
                'project_hours' => $request->project_hours,
                'project_tag_activity' => $request->project_tag_activity,
                'project_used_hours' => $request->project_used_hours,
                'project_used_budget' => $request->project_used_budget,
                'offline_hours' => $request->offline_hours,
            ]);

            $relation = ProjectRelation::create([
                'client_id' => $request->client_id,
                'project_id' => $project->id,
                'communication_id' => $communication_id,
                'assignees' => $assignees,
                'source_id' => $request->source_id,
                'account_id' => $request->account_id,
                'tracking_id' => $request->tracking_id,
                'sales_person_id' => $request->sales_person_id,
                'project_estimation_by' => $request->project_estimation_by,
                'project_call_by' => $request->project_call_by,
            ]);

            DB::commit();

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => $request->project_name . ' Project created by ' . auth()->user()->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Project created successfully',
                'data' => [
                    'project' => new ProjectMasterResource($project),
                    'relation' => new ProjectRelationResource($relation)
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Project creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function show(string $id)
    {
        if (!$id) {
            return response()->json([
                'success' => false,
                'message' => 'Project id is required',
            ], 422);
        }

        $project = ProjectMaster::find($id);

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found',
            ], 404);
        }

        $relation = ProjectRelation::where('project_id', $project->id)->first();

        return response()->json([
            'success' => true,
            'project' => new ProjectMasterResource($project),
            'relation' => $relation
                ? new ProjectRelationResource($relation)
                : null,
        ], 200);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'project_name' => 'sometimes|required|string|max:255',
            'client_id' => 'sometimes|required|integer|exists:clients_master,id',
            'communication_id' => 'sometimes|required',
            'assignees' => 'sometimes|required',
            'source_id' => 'sometimes|required|integer|exists:project_source,id',
            'account_id' => 'sometimes|required|integer|exists:project_accounts,id',
            'sales_person_id' => 'sometimes|nullable|integer|exists:users,id',
            'project_estimation_by' => 'sometimes|nullable|integer|exists:users,id',
            'project_call_by' => 'sometimes|nullable|integer|exists:users,id',
            'project_tracking' => 'sometimes|required|integer',
            'tracking_id' => 'sometimes|nullable|integer',
            'project_status' => 'sometimes|nullable|string',
            'project_description' => 'sometimes|nullable|string',
            'project_budget' => 'sometimes|nullable|string',
            'project_hours' => 'sometimes|nullable|string',
            'project_tag_activity' => 'sometimes|required|integer',
            'project_used_hours' => 'sometimes|nullable|string',
            'project_used_budget' => 'sometimes|nullable|string',
            'offline_hours' => 'sometimes|nullable|integer',
        ], [
            'client_id.exists' => 'Client does not exist',
            'source_id.exists' => 'Source does not exist',
            'account_id.exists' => 'Account does not exist',
            'sales_person_id.exists' => 'Sales person does not exist',
            'project_estimation_by.exists' => 'Project Estimation by User does not exist',
            'project_call_by.exists' => 'Project Call Taken by User does not exist',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 200);
        }

        try {
            $project = ProjectMaster::find($id);

            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found',
                    'data' => null
                ], 200);
            }

            $relation = ProjectRelation::where('project_id', $project->id)->first();

            if (!$relation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project relation not found',
                    'data' => null
                ], 200);
            }


            $projectData = $request->only([
                'project_name',
                'project_tracking',
                'project_status',
                'project_description',
                'project_budget',
                'project_hours',
                'project_tag_activity',
                'project_used_hours',
                'project_used_budget',
                'offline_hours',
            ]);

            if (!empty($projectData)) {
                $project->update($projectData);
            }


            if ($request->has('communication_id')) {
                $communication_id = $request->communication_id;

                if (is_string($communication_id)) {
                    $communication_id = array_filter(
                        array_map('intval', explode(',', $communication_id))
                    );
                }

                if (!is_array($communication_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid communication format'
                    ], 200);
                }

                $existingIds = CommunicationType::whereIn('id', $communication_id)
                    ->pluck('id')
                    ->toArray();

                if (count($existingIds) !== count($communication_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more communication types do not exist'
                    ], 200);
                }

                $relation->communication_id = $communication_id;
            }


            if ($request->has('assignees')) {
                $assignees = $request->assignees;

                if (is_string($assignees)) {
                    $assignees = array_filter(
                        array_map('intval', explode(',', $assignees))
                    );
                }

                if (!is_array($assignees) || empty($assignees)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid assignees format'
                    ], 200);
                }

                $existingAssignees = User::whereIn('id', $assignees)->where('is_active', 1)
                    ->pluck('id')
                    ->toArray();

                if (count($existingAssignees) !== count($assignees)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more assignees do not exist'
                    ], 200);
                }

                $relation->assignees = $assignees;
            }


            $relationData = $request->only([
                'client_id',
                'source_id',
                'account_id',
                'tracking_id',
                'sales_person_id',
                'project_estimation_by',
                'project_call_by',
            ]);

            if (!empty($relationData)) {
                $relation->fill($relationData);
            }

            $relation->save();

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => $project->project_name . ' Project updated by ' . auth()->user()->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Project updated successfully',
                'data' => [
                    'project' => new ProjectMasterResource($project),
                    'relation' => new ProjectRelationResource($relation)
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Project update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    // public function update(Request $request, $id)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'project_name' => 'required|string|max:255',
    //         'client_id' => 'required|integer|exists:clients_master,id',
    //         'communication_id' => 'required',
    //         'assignees' => 'required',
    //         'source_id' => 'required|integer|exists:project_source,id',
    //         'account_id' => 'required|integer|exists:project_accounts,id',
    //         'sales_person_id' => 'required|integer|exists:users,id',
    //         'project_tracking' => 'required|integer',
    //         'project_status' => 'nullable|string',
    //         'project_description' => 'nullable|string',
    //         'project_budget' => 'nullable|string',
    //         'project_hours' => 'nullable|string',
    //         'project_tag_activity' => 'required|integer',
    //         'project_used_hours' => 'nullable|string',
    //         'project_used_budget' => 'nullable|string',
    //     ], [
    //         'client_id.exists' => 'Client does not exist',
    //         'source_id.exists' => 'Source does not exist',
    //         'account_id.exists' => 'Account does not exist',
    //         'sales_person_id.exists' => 'Sales person does not exist',
    //     ]);
    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $validator->errors()->first()
    //         ], 200);
    //     }

    //     $communication_id = $request->communication_id;

    //     if (is_string($communication_id)) {
    //         $communication_id = array_filter(
    //             array_map('intval', explode(',', $communication_id))
    //         );
    //     }

    //     if (!is_array($communication_id)) {
    //         $communication_id = [];
    //     }

    //     $existingIds = CommunicationType::whereIn('id', $communication_id)
    //         ->pluck('id')
    //         ->toArray();

    //     $missing = array_diff($communication_id, $existingIds);

    //     if (!empty($missing)) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'One or more communication types do not exist'
    //         ], 200);
    //     }

    //     $assignees = $request->assignees;

    //     if (is_string($assignees)) {
    //         $assignees = array_filter(
    //             array_map('intval', explode(',', $assignees))
    //         );
    //     }

    //     if (!is_array($assignees) || empty($assignees)) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Invalid assignees format'
    //         ], 200);
    //     }

    //     $existingAssignees = User::whereIn('id', $assignees)
    //         ->pluck('id')
    //         ->toArray();

    //     if (count($existingAssignees) !== count($assignees)) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'One or more assignees do not exist'
    //         ], 200);
    //     }


    //     try {
    //         $project = ProjectMaster::find($id);

    //         if (!$project) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Project not found',
    //                 'data' => null
    //             ], 200);
    //         }

    //         $project->update([
    //             'project_name' => $request->project_name,
    //             'project_tracking' => $request->project_tracking,
    //             'project_status' => $request->project_status,
    //             'project_description' => $request->project_description,
    //             'project_budget' => $request->project_budget,
    //             'project_hours' => $request->project_hours,
    //             'project_tag_activity' => $request->project_tag_activity,
    //             'project_used_hours' => $request->project_used_hours,
    //             'project_used_budget' => $request->project_used_budget,
    //         ]);

    //         $relation = ProjectRelation::where('project_id', $project->id)->first();

    //         if (!$relation) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Project relation not found',
    //                 'data' => null
    //             ], 200);
    //         }

    //         $relation->update([
    //             'client_id' => $request->client_id,
    //             'communication_id' => $communication_id,
    //             'source_id' => $request->source_id,
    //             'account_id' => $request->account_id,
    //             'sales_person_id' => $request->sales_person_id,
    //             'assignees' => $assignees,
    //         ]);

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Project updated successfully',
    //             'data' => [
    //                 'project' => new ProjectMasterResource($project),
    //                 'relation' => new ProjectRelationResource($relation)
    //             ]
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Project update failed',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }


    public function updatePartial(Request $request, string $id)
    {
        $project = ProjectMaster::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'project_name' => 'sometimes|string|max:255',
            'project_tracking' => 'sometimes|integer',
            'project_status' => 'sometimes|nullable|string',
            'project_description' => 'sometimes|nullable|string',
            'project_budget' => 'sometimes|nullable|string',
            'project_hours' => 'sometimes|nullable|string',
            'project_tag_activity' => 'sometimes|integer|exists:tag_activity,id',
            'project_used_hours' => 'sometimes|nullable|string',
            'project_used_budget' => 'sometimes|nullable|string',
            'offline_hours' => 'sometimes|nullable|string',
        ], [
            'project_tag_activity.exists' => 'Tag activity does not exist',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 200);
        }

        $project->update($request->only([
            'project_name',
            'project_tracking',
            'project_status',
            'project_description',
            'project_budget',
            'project_hours',
            'project_tag_activity',
            'project_used_hours',
            'project_used_budget',
            'offline_hours',
        ]));

        ActivityService::log([
            'project_id' => $project->id,
            'type' => 'activity',
            'description' => $project->project_name . ' Project updated by ' . auth()->user()->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Project updated successfully',
            'data' => new ProjectMasterResource($project->fresh()),
        ], 200);
    }


    public function destroy(string $id)
    {
        $project = ProjectMaster::findOrFail($id);
        $project->delete();
        ActivityService::log([
            'project_id' => $project->id,
            'type' => 'activity',
            'description' => $project->project_name . ' Project deleted by ' . auth()->user()->name,
        ]);
        return response()->json([
            'message' => 'Project deleted successfully',
        ]);
    }


    public function assignProjectToTLMaster(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'project_id' => 'required|exists:projects_master,id',
                'tl_id' => 'required|array',
                'tl_id.*' => 'exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $project = ProjectMaster::findOrFail($validatedData['project_id']);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found',
            ], 404);
        }

        /** Get or create project relation row */
        $relation = ProjectRelation::firstOrCreate(
            ['project_id' => $project->id],
            [
                'assignees' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        /** Existing assignees */
        $existingTlIds = $relation->assignees
            ? $relation->assignees
            : [];

        /** Merge new TLs */
        $mergedTlIds = array_values(array_unique(
            array_merge($existingTlIds, $validatedData['tl_id'])
        ));

        /** Save updated assignees */
        $relation->assignees = ($mergedTlIds);
        $relation->updated_at = now();
        $relation->save();

        /** Mail only newly assigned TLs */
        $newlyAssignedTlIds = array_diff($validatedData['tl_id'], $existingTlIds);
        $assigner = auth()->user();

        foreach ($newlyAssignedTlIds as $tlId) {
            $tl = User::where('is_active', 1)->find($tlId);

            if ($tl && $tl->email) {
                $mail = (new ProjectAssignedToTLMail($tl, $project, $assigner))
                    ->replyTo($assigner->email, $assigner->name);

                // Mail::to($tl->email)->queue($mail);
            }

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Project assigned to ' . $tl->name . ' Team Leaders by' . auth()->user()->name,
            ]);
        }


        return response()->json([
            'success' => true,
            'message' => 'Project assigned to Team Leaders successfully.',
            'data' => [
                'project_id' => $project->id,
                'assigned_tl_ids' => $mergedTlIds,
                'assigned_by' => $assigner->id,
                'emails_sent_to' => array_values($newlyAssignedTlIds),
            ],
        ], 200);
    }
    public function removeprojecttlMaster($project_id, $member_id, $type = 'tl')
    {
        if (!is_numeric($project_id)) {
            return response()->json(['error' => 'Invalid project ID'], 422);
        }

        $member_ids = is_array($member_id)
            ? $member_id
            : explode(',', $member_id);

        $relation = ProjectRelation::where('project_id', $project_id)->first();

        if (!$relation) {
            return response()->json(['error' => 'Project relation not found'], 404);
        }

        $assignees = is_array($relation->assignees)
            ? $relation->assignees
            : [];

        $updatedAssignees = array_filter(
            $assignees,
            fn($id) => !in_array($id, $member_ids)
        );

        $relation->assignees = array_values($updatedAssignees);
        $relation->save();

        $userNames = User::whereIn('id', $member_ids)->where('is_active', 1)
            ->pluck('name')
            ->implode(', ');

        ActivityService::log([
            'project_id' => $project_id,
            'type' => 'activity',
            'description' => "{$userNames} unassigned from project by " . auth()->user()->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Team Lead removed successfully.',
            'data' => [
                'project_id' => $relation->project_id,
                'updated_tl_id' => $relation->assignees
            ]
        ]);
    }

    public function assignProjectManagerProjectToEmployeeMaster(Request $request)
    {
        $projectManagerId = auth()->user()->id;
        $validatedData = $request->validate([
            'project_id' => 'required|exists:projects_master,id',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'exists:users,id'
        ]);

        $relation = ProjectRelation::where('project_id', $validatedData['project_id'])->first();

        if (!$relation) {
            return ApiResponse::error(
                'Invalid project_id. Project does not exist.',
                [],
                404
            );
        }

        $assignees = $relation->assignees;
        if (!is_array($assignees)) {
            $assignees = [];
        }

        $insertedData = [];
        $alreadyAssigned = [];

        try {
            foreach ($validatedData['employee_ids'] as $employeeId) {
                if (in_array($employeeId, $assignees)) {
                    $alreadyAssigned[] = $employeeId;
                    continue;
                }

                $assignees[] = $employeeId;

                // Mimic old inserted row response
                $insertedData[] = [
                    'project_id' => $validatedData['project_id'],
                    'user_id' => $employeeId,
                    'project_manager_id' => $projectManagerId
                ];
            }

            $relation->assignees = array_values(array_unique($assignees));
            $relation->updated_at = now();
            $relation->save();
        } catch (\Exception $e) {
            return ApiResponse::error(
                'Database Error: ' . $e->getMessage(),
                [],
                500
            );
        }

        $responseMessage = 'Project assigned successfully';
        if (!empty($alreadyAssigned)) {
            $responseMessage .= '. But these users were already assigned: ' . implode(', ', $alreadyAssigned);
        }

        $userNames = User::whereIn('id', $validatedData['employee_ids'])->where('is_active', 1)
            ->pluck('name')
            ->implode(', ');

        ActivityService::log([
            'project_id' => $validatedData['project_id'],
            'type' => 'activity',
            'description' => "{$userNames} assigned to project by " . auth()->user()->name,
        ]);

        return ApiResponse::success($responseMessage, [
            'project_manager_id' => $projectManagerId,
            'data' => $insertedData
        ]);
    }
    public function removeprojectemployeeMaster($project_id, $user_id)
    {
        if (!is_numeric($project_id) || !is_numeric($user_id)) {
            return response()->json(['error' => 'Invalid parameters'], 422);
        }

        $relation = ProjectRelation::where('project_id', $project_id)->first();

        if (!$relation) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $assignees = $relation->assignees;

        if (!is_array($assignees) || !in_array($user_id, $assignees)) {
            return response()->json([
                'error' => 'User is not assigned to this project'
            ], 404);
        }

        $updatedAssignees = array_values(
            array_diff($assignees, [$user_id])
        );

        $relation->assignees = $updatedAssignees;
        $relation->updated_at = now();
        $relation->save();

        $username = User::where('is_active', 1)
            ->where('id', $user_id)
            ->value('name');


        ActivityService::log([
            'project_id' => $project_id,
            'type' => 'activity',
            'description' => $username . ' unassigned from project by ' . auth()->user()->name,

        ]);

        return response()->json([
            'success' => true,
            'message' => 'User removed from project successfully.',
        ]);
    }
    public function removeAssignee($project_id, $user_id)
    {
        if (!is_numeric($project_id) || !is_numeric($user_id)) {
            return response()->json(['error' => 'Invalid parameters'], 422);
        }

        $relation = ProjectRelation::where('project_id', $project_id)->first();

        if (!$relation) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $assignees = $relation->assignees;

        if (!is_array($assignees) || !in_array($user_id, $assignees)) {
            return response()->json([
                'error' => 'User is not assigned to this project'
            ], 404);
        }

        // Remove user from assignees
        $updatedAssignees = array_values(
            array_diff($assignees, [$user_id])
        );

        $relation->assignees = $updatedAssignees;
        $relation->updated_at = now();
        $relation->save();

        // $username = User::find($user_id)->name;
        $username = User::where('is_active', 1)
            ->where('id', $user_id)
            ->value('name');

        ActivityService::log([
            'project_id' => $project_id,
            'type' => 'activity',
            'description' => $username . ' unassigned from project by ' . auth()->user()->name,

        ]);

        return response()->json([
            'success' => true,
            'message' => 'User removed from project successfully.',
        ]);
    }
    public function assignProjectToManagerMaster(Request $request)
    {
        $validatedData = $request->validate([
            'project_id' => 'required|exists:projects_master,id',
            'project_manager_ids' => 'required|array|min:1',
            'project_manager_ids.*' => 'exists:users,id'
        ]);

        $relation = ProjectRelation::where('project_id', $validatedData['project_id'])->first();

        if (!$relation) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }

        $existingAssignees = $relation->assignees;
        if (!is_array($existingAssignees)) {
            $existingAssignees = [];
        }

        $mergedManagerIds = array_values(
            array_unique(array_merge($existingAssignees, $validatedData['project_manager_ids']))
        );

        $newlyAssignedIds = array_diff($validatedData['project_manager_ids'], $existingAssignees);

        $relation->assignees = ($mergedManagerIds);
        $relation->updated_at = now();
        $relation->save();

        $assigner = auth()->user();
        foreach ($newlyAssignedIds as $managerId) {
            $manager = User::where('is_active', 1)->find($managerId);
            if ($manager && $manager->email) {
                $mail = (new ProjectAssignedMail(
                    $manager,
                    $relation->project_id,
                    $assigner
                ))->replyTo($assigner->email, $assigner->name);

                // Mail::to($manager->email)->queue($mail);
            }
        }

        $userNames = User::whereIn('id', $validatedData['project_manager_ids'])->where('is_active', 1)
            ->pluck('name')
            ->implode(', ');

        ActivityService::log([
            'project_id' => $validatedData['project_id'],
            'type' => 'activity',
            'description' => "{$userNames} assigned to project by " . auth()->user()->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Project assigned successfully and emails sent to new managers.',
            'data' => [
                'project_id' => $validatedData['project_id'],
                'project_manager_ids' => $mergedManagerIds,
                'emails_sent_to' => array_values($newlyAssignedIds)
            ]
        ]);
    }
    public function removeProjectManagersMaster(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'project_id' => 'required|exists:projects_master,id',
                'manager_ids' => 'required|array|min:1',
                'manager_ids.*' => 'integer|exists:users,id'
            ]);

            $relation = ProjectRelation::where('project_id', $validatedData['project_id'])->first();

            if (!$relation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found'
                ], 404);
            }

            $assignees = $relation->assignees;
            if (!is_array($assignees)) {
                $assignees = [];
            }

            $existingManagers = array_intersect($assignees, $validatedData['manager_ids']);
            if (empty($existingManagers)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No project managers found to remove.'
                ], 404);
            }

            $updatedManagers = array_values(
                array_diff($assignees, $validatedData['manager_ids'])
            );

            $relation->assignees = empty($updatedManagers)
                ? []
                : $updatedManagers;

            $relation->updated_at = now();
            $relation->save();

            $userNames = User::whereIn('id', $validatedData['manager_ids'])->where('is_active', 1)
                ->pluck('name')
                ->implode(', ');

            ActivityService::log([
                'project_id' => $validatedData['project_id'],
                'type' => 'activity',
                'description' => "{$userNames} unassigned from project by " . auth()->user()->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Project managers removed successfully.',
                'updated_rows' => count($existingManagers),
                'remaining_managers' => $updatedManagers
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


    public function getUserProjects()
    {
        try {
            $userId = auth()->id();

            $projects = ProjectMaster::with(['relation', 'client'])
                ->get()
                ->filter(function ($project) use ($userId) {

                    if (!$project->relation) {
                        return false;
                    }

                    $assignees = $project->relation->assignees ?? [];

                    if (is_string($assignees)) {
                        $decoded = json_decode($assignees, true);
                        $assignees = is_array($decoded) ? $decoded : [$assignees];
                    }

                    if (is_numeric($assignees)) {
                        $assignees = [$assignees];
                    }

                    return in_array($userId, (array) $assignees);
                })
                ->map(function ($project) {

                    // Tags
                    $tagIds = $project->project_tag_activity
                        ? json_decode($project->project_tag_activity, true)
                        : [];

                    $tags = TagsActivity::whereIn('id', (array) $tagIds)
                        ->get(['id', 'name']);

                    // Tasks
                    $assignedTasks = Task::where('project_id', $project->id)
                        ->with('projectManager:id,name')
                        ->get()
                        ->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'project_id' => $task->project_id,
                            'title' => $task->title,
                            'description' => $task->description,
                            'hours' => $task->hours,
                            'deadline' => $task->deadline,
                            'status' => $task->status,
                            'start_date' => $task->start_date,
                        ];
                    });

                    return [
                        'id' => $project->id,
                        'project_name' => $project->project_name,
                        'project_tracking' => $project->project_tracking,
                        'project_status' => $project->project_status,
                        'project_description' => $project->project_description,
                        'project_budget' => $project->project_budget,
                        'project_hours' => $project->project_hours,
                        'project_used_hours' => $project->project_used_hours,
                        'project_used_budget' => $project->project_used_budget,
                        'offline_hours' => $project->offline_hours,
                        'created_at' => optional($project->created_at)->toDateString(),
                        'updated_at' => optional($project->updated_at)->toDateString(),

                        'client' => $project->client ?? ['message' => 'No Client Found'],
                        'tags_activitys' => $tags,

                        'relation' => [
                            'client_id' => $project->relation->client_id ?? null,
                            'assignees' => $project->relation->assignees ?? [],
                            'assigned_at' => optional($project->relation->created_at)->toDateString(),
                        ],

                        'assigned_tasks' => $assignedTasks,
                    ];
                })
                ->values(); // reset keys

            return ApiResponse::success(
                'User projects fetched successfully',
                $projects
            );
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user projects',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function GetProjectMasterByClientId(Request $request)
    {
        if (!$request->client_id) {
            return response()->json([
                'success' => false,
                'message' => 'client_id is required',
            ], 422);
        }

        $client = ClientMaster::find($request->client_id);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found',
            ], 404);
        }

        $projectIds = ProjectRelation::where('client_id', $request->client_id)
            ->pluck('project_id')
            ->unique()
            ->toArray();

        $projects = ProjectMaster::whereIn('id', $projectIds)
            ->select([
                'id',
                'project_name',
                'project_tracking',
                'project_status',
                'project_budget',
                'project_hours',
                'project_used_hours',
                'project_used_budget',
                'project_tag_activity',
            ])
            ->get();

        $projects->each(function ($project) {
            $project->project_tag_activity_data = $project->tagActivity()->pluck('name')->first();
        });

        return response()->json([
            'success' => true,
            'client' => [
                'id' => $client->id,
                'client_name' => $client->client_name,
                'client_email' => $client->client_email,
                'client_number' => $client->client_number,
            ],
            'projects' => $projects,
        ]);
    }



    public function GetProjectFullDetailByUserId(Request $request)
    {
        if (!$request->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'User Id is required',
            ], 422);
        }

        $currentUser = User::where('is_active', 1)->find($request->user_id);

        if (!$currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $performaUserIds = [$currentUser->id];

        if ($currentUser->hasAnyRole([1, 2])) {
            $teamIds = is_string($currentUser->team_id)
                ? json_decode($currentUser->team_id, true)
                : (array) $currentUser->team_id;

            $performaUserIds = User::where(function ($q) use ($teamIds) {
                foreach ($teamIds as $teamId) {
                    $q->orWhereJsonContains('team_id', $teamId);
                }
            })->where('is_active', 1)->pluck('id')->toArray();
        }

        $usersMap = User::whereIn('id', $performaUserIds)->where('is_active', 1)
            ->pluck('name', 'id')
            ->toArray();

        $query = ProjectMaster::select('id', 'project_name', 'project_hours')
            ->with('tasks:id,project_id,title,status,hours');
        if ($currentUser->hasAnyRole([5, 7])) {

            $query->whereHas('relation', function ($q) use ($currentUser) {
                $q->whereJsonContains('assignees', $currentUser->id);
            });
        } elseif ($currentUser->hasRole(6)) {

            $query->whereHas('relation', function ($q) use ($performaUserIds) {
                foreach ($performaUserIds as $userId) {
                    $q->orWhereJsonContains('assignees', $userId);
                }
            });
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized role',
            ], 403);
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $taskIds = $projects->pluck('tasks')->flatten()->pluck('id')->unique()->toArray();

        $performaSheets = PerformaSheet::whereIn('user_id', $performaUserIds)->get();

        $taskUserMinutes = [];

        foreach ($performaSheets as $sheet) {

            $data = json_decode($sheet->data, true);

            if (!$data || !isset($data['task_id'], $data['time'])) {
                continue;
            }

            $taskId = (int) $data['task_id'];

            if (!in_array($taskId, $taskIds)) {
                continue;
            }

            [$hours, $minutes] = array_map('intval', explode(':', $data['time']));
            $totalMinutes = ($hours * 60) + $minutes;

            $userId = $sheet->user_id;

            $taskUserMinutes[$taskId][$userId] =
                ($taskUserMinutes[$taskId][$userId] ?? 0) + $totalMinutes;
        }

        $projects = $projects->map(function ($project) use ($taskUserMinutes, $usersMap, $currentUser) {

            $project->requested_user_id = $currentUser->id;

            $project->tasks = $project->tasks->map(function ($task) use ($taskUserMinutes, $usersMap) {

                $taskId = $task->id;
                $totalMinutes = 0;
                $users = [];

                if (isset($taskUserMinutes[$taskId])) {

                    foreach ($taskUserMinutes[$taskId] as $userId => $minutes) {

                        $totalMinutes += $minutes;

                        $users[] = [
                            'id' => $userId,
                            'name' => $usersMap[$userId] ?? 'Unknown',
                            'hours' => round($minutes / 60, 2),
                        ];
                    }
                }

                $task->used_hours = round($totalMinutes / 60, 2);
                $task->users = $users;

                return $task;
            });

            return $project;
        });

        return response()->json([
            'success' => true,
            'data' => $projects,
        ]);
    }


    public function GetProjectTaskPerformaByUser(Request $request)
    {
        if (!$request->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'User Id is required',
            ], 422);
        }

        $user = User::where('id', $request->user_id)
            ->where('is_active', 1)
            ->first();


        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        // if (!in_array($user->role_id, [5, 6, 7])) {
        if (!$user->hasAnyRole([5, 6, 7])) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized role',
            ], 403);
        }

        $projects = ProjectMaster::select('id', 'project_name')
            ->whereHas('relation', function ($q) use ($user) {
                $q->whereJsonContains('assignees', $user->id);
            })
            ->with('tasks:id,project_id,title,status,hours')
            ->get();

        if ($projects->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $taskIds = $projects->pluck('tasks')
            ->flatten()
            ->pluck('id')
            ->unique()
            ->toArray();

        // if ($user->role_id == 7) {
        if ($user->hasRole(7)) {
            $performaUserIds = [$user->id];
        } else {
            $teamIds = is_string($user->team_id)
                ? json_decode($user->team_id, true)
                : (array) $user->team_id;

            $performaUserIds = User::where(function ($q) use ($teamIds) {
                foreach ($teamIds as $teamId) {
                    $q->orWhereJsonContains('team_id', (int) $teamId);
                }
            })->where('is_active', 1)
                ->pluck('id')
                ->toArray();
        }

        $usersMap = User::whereIn('id', $performaUserIds)->where('is_active', 1)
            ->pluck('name', 'id')
            ->toArray();

        $performaSheets = PerformaSheet::whereIn('user_id', $performaUserIds)
            ->where('status', 'approved')
            ->get();


        $taskUserMinutes = [];

        foreach ($performaSheets as $sheet) {

            $decoded = json_decode($sheet->data, true);

            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }

            $entries = isset($decoded[0]) ? $decoded : [$decoded];

            foreach ($entries as $entry) {

                if (!isset($entry['task_id'], $entry['time'])) {
                    continue;
                }

                $taskId = (int) $entry['task_id'];

                if (!in_array($taskId, $taskIds)) {
                    continue;
                }

                [$h, $m] = array_map('intval', explode(':', $entry['time']));
                $minutes = ($h * 60) + $m;

                $taskUserMinutes[$taskId][$sheet->user_id] =
                    ($taskUserMinutes[$taskId][$sheet->user_id] ?? 0) + $minutes;
            }
        }


        $projects = $projects->map(function ($project) use ($taskUserMinutes, $usersMap) {

            $project->tasks = $project->tasks->map(function ($task) use ($taskUserMinutes, $usersMap) {

                $totalMinutes = 0;
                $users = [];

                if (isset($taskUserMinutes[$task->id])) {
                    foreach ($taskUserMinutes[$task->id] as $userId => $minutes) {
                        $totalMinutes += $minutes;

                        $users[] = [
                            'id' => $userId,
                            'name' => $usersMap[$userId] ?? 'Unknown',
                            'hours' => round($minutes / 60, 2),
                        ];
                    }
                }

                $task->used_hours = round($totalMinutes / 60, 2);
                $task->users = $users;

                return $task;
            });

            return $project;
        });

        return response()->json([
            'success' => true,
            'data' => $projects,
        ]);
    }


    public function getFullDetailsOfProjectById(Request $request)
    {
        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)->startOfDay()
            : null;

        $endDate = $request->end_date
            ? Carbon::parse($request->end_date)->endOfDay()
            : null;
        if (!$request->project_id) {
            return response()->json([
                'success' => false,
                'message' => 'Project Id is required',
            ], 422);
        }
        $project = ProjectMaster::select('id', 'project_name')
            ->where('id', $request->project_id)
            ->with([
                'tasks:id,project_id,title,status,hours',
                'relation:id,project_id,assignees'
            ])
            ->first();

        if (!$project) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found',
            ], 404);
        }

        $assignees = [];

        if ($project->relation && $project->relation->assignees) {
            $assignees = is_string($project->relation->assignees)
                ? json_decode($project->relation->assignees, true)
                : (array) $project->relation->assignees;
        }

        if (empty($assignees)) {
            unset($project->relation);
            return response()->json([
                'success' => true,
                'data' => $project,
            ]);
        }

        $usersMap = User::whereIn('id', $assignees)->where('is_active', 1)
            ->pluck('name', 'id')
            ->toArray();

        $taskIds = $project->tasks->pluck('id')->toArray();

        $performaSheets = PerformaSheet::whereIn('user_id', $assignees)
            ->where('status', 'approved')
            ->get();

        $taskUserData = [];

        foreach ($performaSheets as $sheet) {
            $decoded = json_decode($sheet->data, true);

            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }

            $entries = isset($decoded[0]) ? $decoded : [$decoded];

            foreach ($entries as $entry) {
                if (!isset($entry['task_id'], $entry['time'], $entry['date'])) {
                    continue;
                }
                $entryDate = Carbon::parse($entry['date']);
                if ($startDate && $entryDate->lt($startDate)) {
                    continue;
                }
                if ($endDate && $entryDate->gt($endDate)) {
                    continue;
                }

                $taskId = (int) $entry['task_id'];

                if (!in_array($taskId, $taskIds)) {
                    continue;
                }

                [$h, $m] = array_map('intval', explode(':', $entry['time']));
                $minutes = ($h * 60) + $m;

                $taskUserData[$taskId][$sheet->user_id]['minutes'] =
                    ($taskUserData[$taskId][$sheet->user_id]['minutes'] ?? 0) + $minutes;

                $taskUserData[$taskId][$sheet->user_id]['sheets'][] = [
                    'sheet_id' => $sheet->id,
                    'status' => $sheet->status,
                    'project_id' => $entry['project_id'],
                    'task_id' => $entry['task_id'],
                    'date' => $entry['date'] ?? null,
                    'time' => $entry['time'],
                    'activity_type' => $entry['activity_type'] ?? null,
                    'work_type' => $entry['work_type'] ?? null,
                    'narration' => $entry['narration'] ?? null,
                ];
            }
        }

        $project->tasks = $project->tasks->map(function ($task) use ($taskUserData, $usersMap) {

            $totalMinutes = 0;
            $users = [];

            if (isset($taskUserData[$task->id])) {
                foreach ($taskUserData[$task->id] as $userId => $data) {
                    $totalMinutes += $data['minutes'];
                    $users[] = [
                        'id' => $userId,
                        'name' => $usersMap[$userId] ?? 'Unknown',
                        'hours' => round($data['minutes'] / 60, 2),
                        'sheets' => collect($data['sheets'] ?? [])
                            ->sortByDesc('sheet_id')
                            ->values()
                            ->toArray(),
                    ];
                }
            }


            $task->used_hours = round($totalMinutes / 60, 2);
            $task->users = $users;

            return $task;
        });

        $projectUserData = [];

        foreach ($performaSheets as $sheet) {

            $decoded = json_decode($sheet->data, true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }

            $entries = isset($decoded[0]) ? $decoded : [$decoded];

            foreach ($entries as $entry) {
                if (!isset($entry['project_id'], $entry['time'], $entry['date'])) {
                    continue;
                }

                if ((int) $entry['project_id'] !== (int) $project->id) {
                    continue;
                }

                $entryDate = Carbon::parse($entry['date']);
                if ($startDate && $entryDate->lt($startDate))
                    continue;
                if ($endDate && $entryDate->gt($endDate))
                    continue;

                [$h, $m] = array_map('intval', explode(':', $entry['time']));
                $minutes = ($h * 60) + $m;

                $projectUserData[$sheet->user_id]['minutes'] =
                    ($projectUserData[$sheet->user_id]['minutes'] ?? 0) + $minutes;

                $projectUserData[$sheet->user_id]['sheets'][] = [
                    'sheet_id' => $sheet->id,
                    'status' => $sheet->status,
                    'project_id' => $entry['project_id'],
                    'task_id' => $entry['task_id'] ?? null,
                    'date' => $entry['date'] ?? null,
                    'time' => $entry['time'],
                    'activity_type' => $entry['activity_type'] ?? null,
                    'work_type' => $entry['work_type'] ?? null,
                    'narration' => $entry['narration'] ?? null,
                ];
            }
        }
        $projectUsers = [];
        foreach ($projectUserData as $userId => $data) {
            $projectUsers[] = [
                'id' => $userId,
                'name' => $usersMap[$userId] ?? 'Unknown',
                'hours' => round($data['minutes'] / 60, 2),
                'sheets' => collect($data['sheets'] ?? [])
                    ->sortByDesc('sheet_id')
                    ->values()
                    ->toArray(),
            ];
        }

        $project->projectSheets = [
            'users' => $projectUsers
        ];

        unset($project->relation);

        return response()->json([
            'success' => true,
            'data' => $project,
        ]);
    }

    public function getProjectsMasterNameId()
    {
        $currentUser = auth()->user();

        if ($currentUser->hasAnyRole([1, 2, 3, 4])) {

            $projects = ProjectMaster::select('id', 'project_name')
                ->with(['relation:id,project_id,assignees'])
                ->get();
        } else {

            $projects = ProjectMaster::select('id', 'project_name')
                ->with(['relation:id,project_id,assignees'])
                ->get()
                ->filter(function (ProjectMaster $project) use ($currentUser) {

                    if (!$project->relation) {
                        return false;
                    }

                    $assignees = $project->relation->assignees ?? [];

                    if (is_numeric($assignees)) {
                        $assignees = [(int) $assignees];
                    } elseif (is_string($assignees)) {
                        $assignees = json_decode($assignees, true) ?? [];
                    } elseif (!is_array($assignees)) {
                        $assignees = [];
                    }

                    return in_array($currentUser->id, $assignees, true);
                })
                ->values();
        }

        $data = $projects->map(function (ProjectMaster $project) {
            return [
                'id' => $project->id,
                'project_name' => $project->project_name,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ], 200);
    }

    public function getUsersAllSheetsDataReporting(Request $request)
    {
        $departmentIds = collect(explode(',', $request->department_id ?? ''))
            ->map(fn($v) => trim($v))
            ->filter(fn($v) => is_numeric($v))
            ->map(fn($v) => (int) $v)
            ->values();

        $teamIds = collect(explode(',', $request->team_id ?? ''))
            ->map(fn($v) => trim($v))
            ->filter(fn($v) => is_numeric($v))
            ->map(fn($v) => (int) $v)
            ->values();
        $userIds = collect(explode(',', $request->user_id ?? ''))
            ->map(fn($v) => trim($v))
            ->filter(fn($v) => is_numeric($v))
            ->map(fn($v) => (int) $v)
            ->values();

        $clientIds = collect(explode(',', $request->client_id ?? ''))
            ->map(fn($v) => trim($v))
            ->filter(fn($v) => is_numeric($v))
            ->map(fn($v) => (int) $v)
            ->values();

        $projectIds = collect(explode(',', $request->project_id ?? ''))
            ->map(fn($v) => trim($v))
            ->filter(fn($v) => is_numeric($v))
            ->map(fn($v) => (int) $v)
            ->values();

        $activityTags = collect(explode(',', $request->activity_tag ?? ''))
            ->map(fn($v) => trim($v))
            ->filter(fn($v) => is_numeric($v))
            ->map(fn($v) => (int) $v)
            ->values();

        $activityIdToType = [
            5 => 'billable',
            8 => 'in-house',
            18 => 'no work',
        ];
        $allowedActivityTypes = $activityTags
            ->map(fn($id) => $activityIdToType[$id] ?? null)
            ->filter()
            ->values()
            ->toArray();
        $status = $request->status
            ? collect(explode(',', $request->status))->map('trim')->toArray()
            : null;

        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $endDate = $request->end_date
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::yesterday()->endOfDay();

        // Expected working dates
        $expectedDates = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            if (!$cursor->isWeekend()) {
                $expectedDates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        /*ELIGIBLE USERS*/
        $eligibleUsers = User::query()
            ->select('id', 'name', 'team_id', 'role_id','created_at')
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereJsonDoesntContain('team_id', 2);
            })
            ->whereJsonContains('role_id', 7)
            ->where(function ($q) use ($userIds, $teamIds, $departmentIds) {

                if ($userIds->isNotEmpty()) {
                    $q->whereIn('id', $userIds);
                    return;
                }

                if ($teamIds->isNotEmpty()) {
                    $q->where(function ($sub) use ($teamIds) {
                        foreach ($teamIds as $teamId) {
                            $sub->orWhereJsonContains('team_id', $teamId);
                        }
                    });
                    return;
                }

                if ($departmentIds->isNotEmpty()) {

                    $teamIdsFromDept = Team::whereIn('department_id', $departmentIds)
                        ->pluck('id');

                    if ($teamIdsFromDept->isEmpty()) {
                        // No teams → no users
                        $q->whereRaw('0 = 1');
                        return;
                    }

                    $q->where(function ($sub) use ($teamIdsFromDept) {
                        foreach ($teamIdsFromDept as $teamId) {
                            $sub->orWhereJsonContains('team_id', $teamId);
                        }
                    });
                }
            })
            ->get()
            ->keyBy('id');
        $eligibleUserIds = $eligibleUsers->keys();
        $filteredProjectIds = ProjectMaster::query()
            ->when($projectIds->isNotEmpty(), function ($q) use ($projectIds) {
                $q->whereIn('id', $projectIds);
            })
            ->when(
                $activityTags->isNotEmpty(),
                fn($q) =>
                $q->whereIn('project_tag_activity', $activityTags)
            )
            ->whereHas('relation', function ($q) use ($clientIds, $eligibleUserIds) {

                // Client filter
                if ($clientIds->isNotEmpty()) {
                    $q->whereIn('client_id', $clientIds);
                }

                // Assignee filter (OR JSON logic)
                if ($eligibleUserIds->isNotEmpty()) {
                    $q->where(function ($sub) use ($eligibleUserIds) {
                        foreach ($eligibleUserIds as $uid) {
                            $sub->orWhereJsonContains('assignees', $uid);
                        }
                    });
                }
            })

            ->pluck('id')
            ->toArray();
        $allTeamIds = $eligibleUsers->pluck('team_id')->flatten()->unique()->toArray();

        $teamNamesMap = Team::whereIn('id', $allTeamIds)
            ->pluck('name', 'id')
            ->toArray();

        $allSheets = PerformaSheet::with('user:id,name,team_id,is_active')
            ->when($status, fn($q) => $q->whereIn('status', $status))
            ->when(
                $userIds->isNotEmpty(),
                fn($q) =>
                $q->whereIn('user_id', $userIds)
            )
            ->get()
            ->filter(function ($sheet) use ($filteredProjectIds, $startDate, $endDate) {

                $data = json_decode($sheet->data, true);
                if (!is_array($data) || empty($data['date'])) {
                    return false;
                }

                $date = Carbon::parse($data['date']);

                if ($date->isWeekend() || !$date->between($startDate, $endDate)) {
                    return false;
                }

                if (!in_array((int) $data['project_id'], $filteredProjectIds, true)) {
                    return false;
                }

                return true;
            });


        $timeToMinutes = fn($t) => ($t && str_contains($t, ':'))
            ? ((int) explode(':', $t)[0] * 60 + (int) explode(':', $t)[1])
            : 0;

        $allSheetsForUnfilled = PerformaSheet::with('user:id,name,team_id,is_active')
            ->whereIn('user_id', $eligibleUsers->keys())
            ->get()->filter(function ($sheet) use ($startDate, $endDate) {

                $data = json_decode($sheet->data, true);
                if (!is_array($data) || empty($data['date'])) {
                    return false;
                }

                $date = Carbon::parse($data['date']);

                if ($date->isWeekend() || !$date->between($startDate, $endDate)) {
                    return false;
                }

                return true;
            });
        $workedMinutesByUserDate = [];
        foreach ($allSheetsForUnfilled as $sheet) {

            $data = json_decode($sheet->data, true);
            if (!is_array($data) || empty($data['date']))
                continue;

            $uid = (int) $sheet->user_id;
            if (!isset($eligibleUsers[$uid]))
                continue;

            $date = Carbon::parse($data['date']);
            if ($date->isWeekend() || !$date->between($startDate, $endDate))
                continue;

            $dateStr = $date->toDateString();

            $workedMinutesByUserDate[$uid][$dateStr] =
                ($workedMinutesByUserDate[$uid][$dateStr] ?? 0)
                + $timeToMinutes($data['time'] ?? null);
        }

        /*LEAVES*/
        $leaves = LeavePolicy::whereIn('user_id', $eligibleUsers->keys())
            ->where('status', 'Approved')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate);
            })
            ->get()
            ->groupBy('user_id');

        $notFilledUsers = [];

        foreach ($eligibleUsers as $user) {

            $uid = (int) $user->id;
            $missingDates = [];
            $missingMinutes = 0;

            $userCreatedDate = Carbon::parse($user->created_at)->startOfDay();
            $effectiveStart = $startDate->copy();
            if ($userCreatedDate->gt($effectiveStart)) {
                $effectiveStart = $userCreatedDate;
            }

            foreach ($expectedDates as $dateStr) {

                if (Carbon::parse($dateStr)->lt($effectiveStart)) {
                    continue;
                }

                $baseRequired = 510;
                $fillableMinutes = 510;
                $worked = $workedMinutesByUserDate[$uid][$dateStr] ?? 0;

                // Determine leave for this date
                if (!empty($leaves[$uid])) {

                    foreach ($leaves[$uid] as $leave) {

                        if ($leave->start_date <= $dateStr && $leave->end_date >= $dateStr) {

                            switch (strtolower($leave->leave_type)) {

                                case 'multiple days leave':
                                    $fillableMinutes = 0;
                                    break 2;

                                case 'full leave':
                                    $fillableMinutes = 0;
                                    break 2;

                                case 'half day':
                                    $fillableMinutes = min($fillableMinutes, 255);
                                    break;
                                case 'short leave':
                                    $fillableMinutes = min($fillableMinutes, 390);
                                    break;
                            }
                        }
                    }
                }

                // Skip full leave day completely
                if ($fillableMinutes === 0) {
                    continue;
                }

                // Final comparison (THIS is the key fix)
                if ($worked < $fillableMinutes) {
                    $missingDates[] = $dateStr;
                    $missingMinutes += ($fillableMinutes - $worked);
                }
            }

            if (!empty($missingDates)) {
                $notFilledUsers[] = [
                    'user_id' => $uid,
                    'user_name' => $user->name,
                    'missing_dates' => $missingDates,
                    'missing_days' => count($missingDates),
                    'missing_minutes' => $missingMinutes,
                ];
            }
        }

        $summary = ['billable' => 0, 'inhouse' => 0, 'no_work' => 0];
        $usersData = [];
        $userCategoryFlags = [];
        foreach ($allSheets as $sheet) {

            $data = json_decode($sheet->data, true);
            if (!is_array($data) || empty($data['date']))
                continue;

            $date = Carbon::parse($data['date']);
            if ($date->isWeekend() || !$date->between($startDate, $endDate))
                continue;

            $uid = (int) $sheet->user_id;
            if (!isset($eligibleUsers[$uid]))
                continue;

            $type = strtolower($data['activity_type'] ?? '');
            $minutes = $timeToMinutes($data['time'] ?? null);

            if (
                !empty($allowedActivityTypes) &&
                !in_array($type, $allowedActivityTypes, true)
            ) {
                continue;
            }

            // Apply filters ONLY here
            /*  if ($project_id && ($data['project_id'] ?? null) != $project_id)
                continue;
            if ($activity_tag && strtolower($data['activity_type'] ?? '') !== strtolower($activity_tag))
                continue; 

            $minutes = $timeToMinutes($data['time'] ?? null);
            $type = strtolower($data['activity_type'] ?? '');
            */
            $userTeamIds = $eligibleUsers[$uid]->team_id ?? [];
            $userTeamNames = [];
            if (is_array($userTeamIds)) {
                foreach ($userTeamIds as $tid) {
                    if (isset($teamNamesMap[$tid])) {
                        $userTeamNames[] = $teamNamesMap[$tid];
                    }
                }
            }
            if (!isset($usersData[$uid])) {
                $usersData[$uid] = [
                    'user_id' => $uid,
                    'user_name' => $eligibleUsers[$uid]->name,
                    'team_names' => $userTeamNames,
                    'summary' => ['billable' => 0, 'inhouse' => 0, 'no_work' => 0],
                    'sheets' => []
                ];

                $userCategoryFlags[$uid] = [
                    'billable' => false,
                    'inhouse' => false,
                    'no_work' => false,
                ];
            }

            if ($type === 'billable') {
                $summary['billable'] += $minutes;
                $usersData[$uid]['summary']['billable'] += $minutes;
                $userCategoryFlags[$uid]['billable'] = true;
            } elseif (in_array($type, ['inhouse', 'in-house'])) {
                $summary['inhouse'] += $minutes;
                $usersData[$uid]['summary']['inhouse'] += $minutes;
                $userCategoryFlags[$uid]['inhouse'] = true;
            } elseif (in_array($type, ['no work', 'no-work'])) {
                $summary['no_work'] += $minutes;
                $usersData[$uid]['summary']['no_work'] += $minutes;
                $userCategoryFlags[$uid]['no_work'] = true;
            }
            $project = ProjectMaster::with('client')->find($data['project_id'] ?? null);
            $projectName = $project->project_name ?? null;
            $clientName = $project?->client?->client_name ?? null;

            $data['project_name'] = $projectName;
            $data['status'] = $sheet->status;
            $data['client_name'] = $clientName;
            $data['created_at'] = $sheet->created_at;
            $data['updated_at'] = $sheet->updated_at;

            $usersData[$uid]['sheets'][] = $data;
        }

        $userCounts = ['billable' => 0, 'inhouse' => 0, 'no_work' => 0];

        foreach ($userCategoryFlags as $flags) {
            if ($flags['billable'])
                $userCounts['billable']++;
            if ($flags['inhouse'])
                $userCounts['inhouse']++;
            if ($flags['no_work'])
                $userCounts['no_work']++;
        }

        $toTime = function ($m) {
            if (is_string($m)) {
                // Already formatted like HH:MM
                if (str_contains($m, ':')) {
                    return $m;
                }

                // Numeric string
                $m = (int) $m;
            }

            if (!is_int($m)) {
                $m = 0;
            }

            return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        };


        $summary = array_map($toTime, $summary);
        foreach ($usersData as &$u) {
            $u['summary'] = array_map($toTime, $u['summary']);
        }

        return response()->json([
            'success' => true,
            'message' => 'All Reporting data',
            'data' => [
                'summary' => array_map($toTime, $summary),
                'user_counts' => $userCounts,
                'users' => array_values(
                    array_map(function ($u) use ($toTime) {
                        $u['summary'] = array_map($toTime, $u['summary']);
                        return $u;
                    }, $usersData)
                ),
                'not_filled' => [
                    'count' => count($notFilledUsers),
                    'users' => $notFilledUsers
                ]
            ]
        ]);
    }

    public function getFilterOfAllDataMasterReporting(Request $request)
    {
        $userIds = $request->user_id
            ? array_map('intval', explode(',', $request->user_id))
            : [];

        $teamIds = $request->team_id
            ? array_map('intval', explode(',', $request->team_id))
            : [];

        $projectIds = $request->project_id
            ? array_map('intval', explode(',', $request->project_id))
            : [];

        $activityTagIds = $request->activity_id
            ? array_map('intval', explode(',', $request->activity_id))
            : [];

        $clientIds = $request->client_id
            ? array_map('intval', explode(',', $request->client_id))
            : [];

        $departmentIds = $request->department_id
            ? array_map('intval', explode(',', $request->department_id))
            : [];

        /* DEPARTMENTS*/
        $departments = Department::select('id', 'name')->get();

        $userTeamIds = [];

        $userTeamIds = User::whereIn('id', $userIds)
            ->pluck('team_id')
            ->flatMap(function ($t) {
                if (is_array($t)) {
                    return $t;
                }

                if (is_string($t)) {
                    return json_decode($t, true) ?? [];
                }

                return [];
            })
            ->unique()
            ->toArray();


        /*TEAMS*/
        $teamsQuery = Team::query()->select('id', 'name', 'department_id');

        if (!empty($userIds)) {
            // Only teams of selected users
            $teamsQuery->whereIn('id', $userTeamIds);
        } elseif (!empty($departmentIds)) {
            $teamsQuery->whereIn('department_id', $departmentIds);
        }

        $teams = $teamsQuery->get();

        /*USERS */
        $usersQuery = User::query()
            ->select('id', 'name', 'team_id')
            ->where('is_active', 1)
            ->whereJsonContains('role_id', 7);

        if (empty($userIds)) {
            // Apply team/department filters ONLY when user_id not provided
            if (!empty($teamIds)) {
                $usersQuery->where(function ($q) use ($teamIds) {
                    foreach ($teamIds as $teamId) {
                        $q->orWhereJsonContains('team_id', $teamId);
                    }
                });
            } elseif (!empty($departmentIds)) {
                $teamIdsInDept = Team::whereIn('department_id', $departmentIds)
                    ->pluck('id')
                    ->toArray();

                $usersQuery->where(function ($q) use ($teamIdsInDept) {
                    foreach ($teamIdsInDept as $teamId) {
                        $q->orWhereJsonContains('team_id', $teamId);
                    }
                });
            }
        }

        $users = $usersQuery->get();
        $finalUserIds = $users->pluck('id')->toArray();

        $teamUserIds = [];

        if (!empty($teamIds)) {
            $teamUserIds = User::where('is_active', 1)
                ->whereJsonContains('role_id', 7)
                ->where(function ($q) use ($teamIds) {
                    foreach ($teamIds as $teamId) {
                        $q->orWhereJsonContains('team_id', $teamId);
                    }
                })
                ->pluck('id')
                ->toArray();
        }

        /*CLIENTS */
        if (!empty($projectIds)) {
            // MOST specific → project filter always wins
            $clients = ClientMaster::select('id', 'client_name')
                ->whereIn(
                    'id',
                    ProjectRelation::whereIn('project_id', $projectIds)
                        ->pluck('client_id')
                        ->unique()
                )
                ->get();
        } else if (!empty($userIds)) {
            // Clients only from projects assigned to selected users
            $clients = ClientMaster::select('id', 'client_name')
                ->whereIn(
                    'id',
                    ProjectRelation::whereIn(
                        'project_id',
                        ProjectRelation::where(function ($q) use ($userIds) {
                            foreach ($userIds as $uid) {
                                $q->orWhereJsonContains('assignees', $uid);
                            }
                        })->pluck('project_id')
                    )->pluck('client_id')->unique()
                )
                ->get();
        } elseif (!empty($teamUserIds)) {

            // Clients from team users' projects
            $clients = ClientMaster::select('id', 'client_name')
                ->whereIn(
                    'id',
                    ProjectRelation::where(function ($q) use ($teamUserIds) {
                        foreach ($teamUserIds as $uid) {
                            $q->orWhereJsonContains('assignees', $uid);
                        }
                    })->pluck('client_id')->unique()
                )
                ->get();
        } else {
            // All clients
            $clients = ClientMaster::select('id', 'client_name')->get();
        }


        /* PROJECTS */
        $projectsQuery = ProjectMaster::query()
            ->select('id', 'project_name', 'project_tag_activity');

        if (!empty($userIds)) {
            $projectsQuery->whereHas('relation', function ($q) use ($userIds) {
                $q->where(function ($sub) use ($userIds) {
                    foreach ($userIds as $uid) {
                        $sub->orWhereJsonContains('assignees', $uid);
                    }
                });
            });
        } elseif (!empty($teamUserIds)) {

            $projectsQuery->whereHas('relation', function ($q) use ($teamUserIds) {
                $q->where(function ($sub) use ($teamUserIds) {
                    foreach ($teamUserIds as $uid) {
                        $sub->orWhereJsonContains('assignees', $uid);
                    }
                });
            });
        } elseif (!empty($finalUserIds)) {
            $projectsQuery->whereHas('relation', function ($q) use ($finalUserIds) {
                $q->where(function ($sub) use ($finalUserIds) {
                    foreach ($finalUserIds as $uid) {
                        $sub->orWhereJsonContains('assignees', $uid);
                    }
                });
            });
        }

        if (!empty($clientIds)) {
            $projectsQuery->whereHas('relation', function ($q) use ($clientIds) {
                $q->whereIn('client_id', $clientIds);
            });
        }

        if (!empty($activityTagIds)) {
            $projectsQuery->whereIn('project_tag_activity', $activityTagIds);
        }

        // if (!empty($finalUserIds)) {
        //     $projectsQuery->whereHas('relation', function ($q) use ($finalUserIds) {
        //         $q->where(function ($sub) use ($finalUserIds) {
        //             foreach ($finalUserIds as $uid) {
        //                 $sub->orWhereJsonContains('assignees', $uid);
        //             }
        //         });
        //     });
        // }

        if (!empty($clientIds)) {
            $projectsQuery->whereHas('relation', function ($q) use ($clientIds) {
                $q->whereIn('client_id', $clientIds);
            });
        }

        $projects = $projectsQuery->get();
        $projectIds = $projects->pluck('id');

        /* PROJECT RELATIONS */
        $relations = ProjectRelation::select('project_id', 'client_id')
            ->whereIn('project_id', $projectIds)
            ->get();

        /*ACTIVITY TAGS*/
        $activityTags = TagsActivity::select('id', 'name')->get();

        if (!empty($request->user_id) && $users->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No Filtered Master Reporting Data Found',
                'data' => [
                    'employees' => [],
                    'teams' => [],
                    'clients' => [],
                    'projects' => [],
                    'activity_tags' => [],
                    'departments' => [],
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Fetched all Filtered Master Reporting Data',
            'data' => [
                'departments' => $departments,
                'teams' => $teams,
                'employees' => $users,
                'clients' => $clients,
                'projects' => $projects,
                'activity_tags' => $activityTags,
            ]
        ]);
    }

    public function getProjectsMasterdetails()
    {
        $currentUser = auth()->user();

        if ($currentUser->hasAnyRole([1, 2, 3, 4])) {

            $projects = ProjectMaster::select('id', 'project_name', 'project_tracking', 'project_status', 'project_tag_activity', 'created_at')
                ->with([
                    'relation:id,project_id,assignees',
                    'client:clients_master.id,clients_master.client_name',
                    'tagActivityRelated:id,name'
                ])
                ->get();
        } else if ($currentUser->hasRole(12)) {

            $projects = ProjectMaster::select(
                'id',
                'project_name',
                'project_tracking',
                'project_status',
                'project_tag_activity',
                'created_at'
            )->with([
                        'relation:id,project_id,assignees,sales_person_id',
                        'client:clients_master.id,clients_master.client_name',
                        'tagActivityRelated:id,name'
                    ])
                ->whereHas('relation', function ($q) use ($currentUser) {
                    $q->where('sales_person_id', $currentUser->id);
                })
                ->get();
        } else {

            $projects = ProjectMaster::select('id', 'project_name', 'project_tracking', 'project_status', 'project_tag_activity', 'created_at')
                ->with([
                    'relation:id,project_id,assignees',
                    'client:clients_master.id,clients_master.client_name',
                    'tagActivityRelated:id,name'
                ])
                ->get()
                ->filter(function (ProjectMaster $project) use ($currentUser) {

                    if (!$project->relation) {
                        return false;
                    }

                    $assignees = $project->relation->assignees ?? [];

                    if (is_numeric($assignees)) {
                        $assignees = [(int) $assignees];
                    } elseif (is_string($assignees)) {
                        $assignees = json_decode($assignees, true) ?? [];
                    } elseif (!is_array($assignees)) {
                        $assignees = [];
                    }

                    return in_array($currentUser->id, $assignees, true);
                })
                ->values();
        }

        $data = $projects->map(function (ProjectMaster $project) {
            return [
                'id' => $project->id,
                'project_name' => $project->project_name,
                'project_tracking' => $project->project_tracking,
                'project_status' => $project->project_status,
                'client_name' => optional($project->client)->client_name,
                'project_tag_activity' => optional($project->tagActivityRelated)->name,
                'created_at' => $project->created_at ? $project->created_at->format('d-m-y') : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ], 200);
    }
}
