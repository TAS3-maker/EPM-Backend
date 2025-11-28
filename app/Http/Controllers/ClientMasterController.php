<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientMasterResource;
use App\Models\ClientMaster;
use Illuminate\Http\Request;

class ClientMasterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return ClientMasterResource::collection(ClientMaster::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'client_name' => 'required|string|max:255',
        ]);

        $client = ClientMaster::create([
            'client_name' => $request->client_name,
        ]);

        return new ClientMasterResource($client);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $client = ClientMaster::findOrFail($id);
        return new ClientMasterResource($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'client_name' => 'required|string|max:255',
        ]);

        $client = ClientMaster::findOrFail($id);

        $client->update([
            'client_name' => $request->client_name,
        ]);

        return new ClientMasterResource($client);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $client = ClientMaster::findOrFail($id);
        $client->delete();

        return response()->json([
            'message' => 'Client deleted successfully',
        ], 200);
    }
}
