<?php

namespace App\Http\Controllers;

use App\Models\ProjectMaster;
use App\Http\Resources\ProjectMasterResource;
use App\Http\Resources\ProjectRelationResource;
use App\Models\ProjectRelation;
use Illuminate\Http\Request;

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
            'communication_id' => 'required|integer',
            'source_id' => 'required|integer',
            'account_id' => 'required|integer',
        ]);
        try {
            //for project
            $project = ProjectMaster::create([
                'project_name' => $request->project_name,
            ]);
            //for relations
            $relation = ProjectRelation::create([
                'client_id' => $request->client_id,
                'project_id' => $project->id,
                'communication_id' => $request->communication_id,
                'source_id' => $request->source_id,
                'account_id' => $request->account_id,
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
            return response()->json([
            'success' => false,
            'message' => 'Project creation failed',
            'error' => $e->getMessage()
        ], 500);
        }
        return new ProjectMasterResource($project);
    }

    public function show(string $id)
    {
        $project = ProjectMaster::findOrFail($id);
        return new ProjectMasterResource($project);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'project_name' => 'required|string|max:255',
        ]);

        $project = ProjectMaster::findOrFail($id);

        $project->update([
            'project_name' => $request->project_name,
        ]);

        return new ProjectMasterResource($project);
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
