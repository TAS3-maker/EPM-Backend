<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientMasterResource;
use App\Models\ClientMaster;
use App\Http\Helpers\ApiResponse;
use Illuminate\Http\Request;

class ClientMasterController extends Controller
{
    public function index()
    {
        $clients = ClientMaster::select('id', 'client_name', 'client_email', 'client_number', 'created_at', 'updated_at')->orderBy('id', 'DESC')->get();
        return ApiResponse::success('Clients fetched successfully', ClientMasterResource::collection($clients));
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
        $client = ClientMasterResource::findOrFail($id);
        $client->delete();

        return response()->json([
            'message' => 'Client deleted successfully',
        ], 200);
    }
}
