<?php

namespace App\Http\Controllers;

use App\Models\ProjectSource;
use App\Http\Resources\ProjectSourceResource;
use Illuminate\Http\Request;

class ProjectSourceController extends Controller
{
    public function index()
    {
        return ProjectSourceResource::collection(ProjectSource::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'source_name' => 'required|string|max:255',
        ]);

        $source = ProjectSource::create([
            'source_name' => $request->source_name,
        ]);

        return new ProjectSourceResource($source);
    }

    public function show($id)
    {
        $source = ProjectSource::find($id);

        if (!$source) {
            return response()->json([
                'success' => false,
                'message' => 'Project source not found',
                'data' => null
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project source fetched successfully',
            'data' => new ProjectSourceResource($source)
        ], 200);
    }


    public function update(Request $request, $id)
    {
        $request->validate([
            'source_name' => 'required|string|max:255',
        ]);

        $source = ProjectSource::find($id);

        if (!$source) {
            return response()->json([
                'success' => false,
                'message' => 'Project source not found',
                'data' => null
            ], 200);
        }

        $source->update([
            'source_name' => $request->source_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Project source updated successfully',
            'data' => new ProjectSourceResource($source)
        ], 200);
    }


    public function destroy($id)
    {
        $source = ProjectSource::find($id);

        if (!$source) {
            return response()->json([
                'success' => false,
                'message' => 'Project source not found',
                'data' => null
            ], 200);
        }

        $source->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project source deleted successfully'
        ], 200);
    }

}
