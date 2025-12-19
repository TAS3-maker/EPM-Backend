<?php

namespace App\Http\Controllers;

use App\Models\ProjectMaster;
use App\Models\CommunicationType;
use App\Http\Resources\ProjectMasterResource;
use App\Http\Resources\ProjectRelationResource;
use App\Models\ProjectRelation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\ProjectActivityAndComment;


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
                'sales_person_id' => $request->sales_person_id,
            ]);

            DB::commit();

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
            'sales_person_id' => 'sometimes|required|integer|exists:users,id',
            'project_tracking' => 'sometimes|required|integer',
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
                'sales_person_id',
            ]);

            if (!empty($relationData)) {
                $relation->fill($relationData);
            }

            $relation->save();

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

        // Validate ONLY fields that may come
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

        // Update ONLY provided fields
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

        return response()->json([
            'message' => 'Project deleted successfully',
        ]);
    }
}
