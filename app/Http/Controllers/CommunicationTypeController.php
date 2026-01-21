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
        $medium = CommunicationType::find($id);

        if (!$medium) {
            return response()->json([
                'success' => false,
                'message' => 'Communication type not found',
                'data' => null
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Communication type fetched successfully',
            'data' => new CommunicationTypeResource($medium)
        ], 200);
    }


    public function update(Request $request, $id)
    {
        $request->validate([
            'medium' => 'required|string|max:255',
            'medium_details' => 'required|string|max:255',
        ]);

        $medium = CommunicationType::find($id);

        if (!$medium) {
            return response()->json([
                'success' => false,
                'message' => 'Communication type not found',
                'data' => null
            ], 200);
        }

        $medium->update([
            'medium' => $request->medium,
            'medium_details' => $request->medium_details,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Communication type updated successfully',
            'data' => new CommunicationTypeResource($medium)
        ], 200);
    }

    public function destroy($id)
    {
        $medium = CommunicationType::find($id);

        if (!$medium) {
            return response()->json([
                'success' => false,
                'message' => 'Communication type not found',
                'data' => null
            ], 200);
        }

        $medium->delete();

        return response()->json([
            'success' => true,
            'message' => 'Communication type deleted successfully'
        ], 200);
    }

}
