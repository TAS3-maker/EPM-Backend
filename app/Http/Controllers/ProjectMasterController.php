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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\ProjectActivityAndComment;
use App\Services\ActivityService;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use League\Config\Exception\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;

class ProjectMasterController extends Controller
{
    public function index()
    {
        $projects = ProjectMaster::all();

        $data = $projects->map(function ($project) {
            $relation = ProjectRelation::where('project_id', $project->id)->first();
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
            'project_tracking' => 'required|integer',
            'tracking_id' => 'nullable|integer',
            'project_status' => 'nullable|string',
            'project_description' => 'nullable|string',
            'project_budget' => 'nullable|string',
            'project_hours' => 'nullable|string',
            'project_tag_activity' => 'required|integer',
            'project_used_hours' => 'nullable|string',
            'project_used_budget' => 'nullable|string',
        ], [
            'client_id.exists' => 'Client does not exist',
            'source_id.exists' => 'Source does not exist',
            'account_id.exists' => 'Account does not exist',
            'sales_person_id.exists' => 'Sales person does not exist',
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

        $existingAssignees = User::whereIn('id', $assignees)
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
            ]);

            DB::commit();

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Project created by ' . auth()->user()->name,
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
        $project = ProjectMaster::findOrFail($id);

        $relation = ProjectRelation::where('project_id', $project->id)->first();

        return response()->json([
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
            'project_tracking' => 'sometimes|required|integer',
            'tracking_id' => 'sometimes|nullable|integer',
            'project_status' => 'sometimes|nullable|string',
            'project_description' => 'sometimes|nullable|string',
            'project_budget' => 'sometimes|nullable|string',
            'project_hours' => 'sometimes|nullable|string',
            'project_tag_activity' => 'sometimes|required|integer',
            'project_used_hours' => 'sometimes|nullable|string',
            'project_used_budget' => 'sometimes|nullable|string',
        ], [
            'client_id.exists' => 'Client does not exist',
            'source_id.exists' => 'Source does not exist',
            'account_id.exists' => 'Account does not exist',
            'sales_person_id.exists' => 'Sales person does not exist',
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

                $existingAssignees = User::whereIn('id', $assignees)
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
            ]);

            if (!empty($relationData)) {
                $relation->fill($relationData);
            }

            $relation->save();

            ActivityService::log([
                'project_id' => $project->id,
                'type' => 'activity',
                'description' => 'Project updated by ' . auth()->user()->name,
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
        ]));

        ActivityService::log([
            'project_id' => $project->id,
            'type' => 'activity',
            'description' => 'Project updated by ' . auth()->user()->name,
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
            'description' => 'Project deleted by ' . auth()->user()->name,
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
            $tl = User::find($tlId);

            if ($tl && $tl->email) {
                $mail = (new ProjectAssignedToTLMail($tl, $project, $assigner))
                    ->replyTo($assigner->email, $assigner->name);

                // Mail::to($tl->email)->send($mail);
            }
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

        if (!is_array($member_id)) {
            $member_ids = explode(',', $member_id);
        }

        $relation = ProjectRelation::where('project_id', $project_id)->first();

        if (!$relation) {
            return response()->json(['error' => 'Project relation not found'], 404);
        }

        $assignees = ($relation->assignees);
        if (!is_array($assignees)) {
            $assignees = [];
        }

        $updatedAssignees = array_filter($assignees, fn($id) => !in_array($id, $member_ids));

        $relation->assignees = (array_values($updatedAssignees));
        $relation->save();

        return response()->json([
            'success' => true,
            'message' => 'Team Lead removed successfully.',
            'data' => [
                'project_id' => $relation->project_id,
                'updated_tl_id' => json_encode($relation->assignees)
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

        return ApiResponse::success($responseMessage, [
            'project_manager_id' => $projectManagerId,
            'data' => $insertedData
        ]);
    }
    public function removeprojectemployeeMaster($project_id, $user_id){
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

        return response()->json([
            'success' => true,
            'message' => 'User removed from project successfully.',
        ]);
    }
    public function removeAssignee($project_id, $user_id){
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

        return response()->json([
            'success' => true,
            'message' => 'User removed from project successfully.',
        ]);
    }
    public function assignProjectToManagerMaster(Request $request){
        $validatedData = $request->validate([
            'project_id' => 'required|exists:projects_master,id',
            'project_manager_ids' => 'required|array|min:1',
            'project_manager_ids.*' => 'exists:users,id'
        ]);

        // Fetch project relation
        $relation = ProjectRelation::where('project_id', $validatedData['project_id'])->first();

        if (!$relation) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found'
            ], 404);
        }

        // Existing assignees (PMs are part of this)
        $existingAssignees = $relation->assignees;
        if (!is_array($existingAssignees)) {
            $existingAssignees = [];
        }

        // Merge without duplicates
        $mergedManagerIds = array_values(
            array_unique(array_merge($existingAssignees, $validatedData['project_manager_ids']))
        );

        // Identify newly assigned managers
        $newlyAssignedIds = array_diff($validatedData['project_manager_ids'], $existingAssignees);

        // Save
        $relation->assignees = ($mergedManagerIds);
        $relation->updated_at = now();
        $relation->save();

        // Send emails only to newly assigned managers
        $assigner = auth()->user();
        foreach ($newlyAssignedIds as $managerId) {
            $manager = User::find($managerId);
            if ($manager && $manager->email) {
                $mail = (new ProjectAssignedMail(
                    $manager,
                    $relation->project_id,
                    $assigner
                ))->replyTo($assigner->email, $assigner->name);

                // Mail::to($manager->email)->send($mail);
            }
        }

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

}
