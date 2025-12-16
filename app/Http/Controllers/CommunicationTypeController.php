<?php

namespace App\Http\Controllers;

use App\Models\CommunicationType;
use App\Http\Resources\CommunicationTypeResource;
use Illuminate\Http\Request;

class CommunicationTypeController extends Controller
{
    public function index()
    {
        return CommunicationTypeResource::collection(CommunicationType::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'medium' => 'required|string|max:255',
            'medium_details' => 'required|string|max:255',
        ]);

        $medium = CommunicationType::create([
            'medium' => $request->medium,
            'medium_details' => $request->medium_details,
        ]);

        return new CommunicationTypeResource($medium);
    }

    public function show($id)
    {
        $medium = CommunicationType::findOrFail($id);
        return new CommunicationTypeResource($medium);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'medium' => 'required|string|max:255',
            'medium_details' => 'required|string|max:255',
        ]);

        $medium = CommunicationType::findOrFail($id);
        $medium->update([
            'medium' => $request->medium,
            'medium_details' => $request->medium_details,
        ]);

        return new CommunicationTypeResource($medium);
    }

    public function destroy($id)
    {
        $medium = CommunicationType::findOrFail($id);
        $medium->delete();

        return response()->json(['message' => 'Communication type deleted successfully']);
    }
}
