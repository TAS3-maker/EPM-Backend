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
        $source = ProjectSource::findOrFail($id);
        return new ProjectSourceResource($source);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'source_name' => 'required|string|max:255',
        ]);

        $source = ProjectSource::findOrFail($id);
        $source->update([
            'source_name' => $request->source_name,
        ]);

        return new ProjectSourceResource($source);
    }

    public function destroy($id)
    {
        $source = ProjectSource::findOrFail($id);
        $source->delete();

        return response()->json(['message' => 'Project source deleted successfully']);
    }
}
