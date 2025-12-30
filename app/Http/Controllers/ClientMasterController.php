<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientMasterResource;
use App\Models\ClientMaster;
use App\Http\Helpers\ApiResponse;
use Illuminate\Http\Request;

class ClientMasterController extends Controller
{
    public function index(Request $request)
    {
        $per_page = $request->get('per_page', 20);
        $clients = ClientMaster::select('id', 'client_name', 'client_email', 'client_number', 'created_at', 'updated_at')->orderBy('id', 'DESC')->paginate($per_page);
        return ApiResponse::success('Clients fetched successfully', 
        [
            'items' => ClientMasterResource::collection($clients),
            'pagination' => [
                'current_page' => $clients->currentPage(),
                'per_page'     => $clients->perPage(),
                'total'        => $clients->total(),
                'last_page'    => $clients->lastPage(),
                'from'         => $clients->firstItem(),
                'to'           => $clients->lastItem(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'client_name' => 'required|string|max:191|unique:clients_master,client_name',
            'client_email' => 'nullable|email|max:191|unique:clients_master,client_email',
            'client_number' => 'nullable|digits_between:10,15|unique:clients_master,client_number',
        ]);

        $client = ClientMaster::create([
            'client_name' => $request->client_name,
            'client_email' => $request->client_email,
            'client_number' => $request->client_number,
        ]);

        return new ClientMasterResource($client);
    }

    public function update(Request $request, $id)
    {
        $client = ClientMaster::find($id);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found'
            ], 404);
        }

        $validated = $request->validate([
            'client_name' => 'required|string|max:191|unique:clients_master,client_name,' . $id,
            'client_email' => 'nullable|email|max:191|unique:clients_master,client_email,' . $id,
            'client_number' => 'nullable|digits_between:10,15|unique:clients_master,client_number,' . $id,
        ]);

        $client->update($validated);

        return new ClientMasterResource($client);
    }



    public function destroy($id)
    {
        $client = ClientMaster::find($id);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found'
            ], 200);
        }

        $client->delete();

        return response()->json([
            'success' => true,
            'message' => 'Client deleted successfully'
        ], 200);
    }
}
