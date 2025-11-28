<?php

namespace App\Http\Controllers;

use App\Models\ProjectMaster;
use App\Http\Resources\ProjectMasterResource;
use Illuminate\Http\Request;

class ProjectMasterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return ProjectMasterResource::collection(ProjectMaster::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'project_name' => 'required|string|max:255',
        ]);

        $project = ProjectMaster::create([
            'project_name' => $request->project_name,
        ]);

        return new ProjectMasterResource($project);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $project = ProjectMaster::findOrFail($id);
        return new ProjectMasterResource($project);
    }

    /**
     * Update the specified resource in storage.
     */
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

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $project = ProjectMaster::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully',
        ]);
    }
}
