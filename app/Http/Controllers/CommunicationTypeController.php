<?php

namespace App\Http\Controllers;

use App\Models\CommunicationType;
use App\Http\Resources\CommunicationTypeResource;
use Illuminate\Http\Request;

class CommunicationTypeController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $search = trim($request->get('search'));
        $searchBy = $request->get('search_by');

        $query = CommunicationType::select(
            'id',
            'medium',
            'medium_details',
            'created_at',
            'updated_at'
        );

        if (!empty($search) && !empty($searchBy)) {

            switch ($searchBy) {

                case 'medium':
                    $query->where('medium', 'LIKE', "%{$search}%");
                    break;

                case 'medium_details':
                    $query->where('medium_details', 'LIKE', "%{$search}%");
                    break;
            }
        }

        $communicationTypes = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $communicationTypes,
        ], 200);
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
