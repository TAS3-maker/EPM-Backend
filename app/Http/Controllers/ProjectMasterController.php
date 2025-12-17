<?php

namespace App\Http\Controllers;

use App\Models\ProjectMaster;
use App\Models\CommunicationType;
use App\Http\Resources\ProjectMasterResource;
use App\Http\Resources\ProjectRelationResource;
use App\Models\ProjectRelation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProjectMasterController extends Controller
{
    public function index()
    {
        return ProjectMasterResource::collection(ProjectMaster::all());
    }


    public function store(Request $request)
    {
        $request->validate([
            'project_name' => 'required|string|max:255',
            'client_id' => 'required|integer',
            'communication_id' => 'required',
            'source_id' => 'required|integer',
            'account_id' => 'required|integer',
            'sales_person_id' => 'required|integer|exists:users,id',
        ]);

        // normalize communication_id
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

        // validate communication IDs
        $existingIds = CommunicationType::whereIn('id', $communication_id)
            ->pluck('id')
            ->toArray();

        if (count($existingIds) !== count($communication_id)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more communication types do not exist'
            ], 200);
        }

        try {
            DB::beginTransaction();

            $project = ProjectMaster::create([
                'project_name' => $request->project_name,
            ]);

            $relation = ProjectRelation::create([
                'client_id' => $request->client_id,
                'project_id' => $project->id,
                'communication_id' => $communication_id, // JSON
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
        return new ProjectMasterResource($project);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'project_name' => 'required|string|max:255',
            'client_id' => 'required|integer',
            'communication_id' => 'required',
            'source_id' => 'required|integer',
            'account_id' => 'required|integer',
            'sales_person_id' => 'required|integer|exists:users,id',
        ]);

        // normalize communication_id
        $communication_id = $request->communication_id;

        if (is_string($communication_id)) {
            $communication_id = array_filter(
                array_map('intval', explode(',', $communication_id))
            );
        }

        if (!is_array($communication_id)) {
            $communication_id = [];
        }

        // validate communication IDs exist
        $existingIds = CommunicationType::whereIn('id', $communication_id)
            ->pluck('id')
            ->toArray();

        $missing = array_diff($communication_id, $existingIds);

        if (!empty($missing)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more communication types do not exist'
            ], 200);
        }

        try {
            // find project
            $project = ProjectMaster::find($id);

            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project not found',
                    'data' => null
                ], 200);
            }

            // update project
            $project->update([
                'project_name' => $request->project_name,
            ]);

            // find relation
            $relation = ProjectRelation::where('project_id', $project->id)->first();

            if (!$relation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Project relation not found',
                    'data' => null
                ], 200);
            }

            // update relation
            $relation->update([
                'client_id' => $request->client_id,
                'communication_id' => $communication_id,
                'source_id' => $request->source_id,
                'account_id' => $request->account_id,
                'sales_person_id' => $request->sales_person_id,
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


    public function destroy(string $id)
    {
        $project = ProjectMaster::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully',
        ]);
    }
}
