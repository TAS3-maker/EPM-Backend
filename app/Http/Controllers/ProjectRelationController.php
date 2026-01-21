<?php

namespace App\Http\Controllers;

use App\Models\ProjectRelation;
use App\Http\Resources\ProjectRelationResource;
use Illuminate\Http\Request;

class ProjectRelationController extends Controller
{
    public function index()
    {
        // return ProjectRelationResource::collection(ProjectRelation::all());
    }

    public function store(Request $request)
    {
        // $request->validate([
        //     'client_id' => 'required|integer',
        //     'project_id' => 'required|integer',
        //     'communication_id' => 'required|integer',
        //     'source_id' => 'required|integer',
        //     'account_id' => 'required|integer',
        //     'sales_person_id' => 'required|integer|exists:users,id',
        // ]);

        // $relation = ProjectRelation::create($request->only(
        //     'client_id',
        //     'project_id',
        //     'communication_id',
        //     'source_id',
        //     'account_id',
        //     'sales_person_id',
        // ));

        // return new ProjectRelationResource($relation);
    }

    public function show($id)
    {
        // $relation = ProjectRelation::findOrFail($id);
        // return new ProjectRelationResource($relation);
    }

    public function update(Request $request, $id)
    {
        // $request->validate([
        //     'client_id' => 'required|integer',
        //     'project_id' => 'required|integer',
        //     'communication_id' => 'required|integer',
        //     'source_id' => 'required|integer',
        //     'account_id' => 'required|integer',
        //     'sales_person_id' => 'required|integer|exists:users,id',
        // ]);

        // $relation = ProjectRelation::findOrFail($id);
        // $relation->update($request->only(
        //     'client_id',
        //     'project_id',
        //     'communication_id',
        //     'source_id',
        //     'account_id',
        //     'sales_person_id' ,
        // ));

        // return new ProjectRelationResource($relation);
    }

    public function destroy($id)
    {
        // $relation = ProjectRelation::findOrFail($id);
        // $relation->delete();

        // return response()->json(['message' => 'Project relation deleted successfully']);
    }
}