<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientMasterResource;
use App\Models\ClientMaster;
use App\Http\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Services\ActivityService;
use Illuminate\Validation\Rule;
class ClientMasterController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $search = trim($request->get('search'));
        $searchBy = $request->get('search_by');

        $query = ClientMaster::select(
            'id',
            'client_name',
            'client_email',
            'client_number',
            'created_at',
            'updated_at'
        )->orderBy('id', 'DESC');

        if (!empty($search) && !empty($searchBy)) {

            switch ($searchBy) {

                case 'client_name':
                    $query->where('client_name', 'LIKE', "%{$search}%");
                    break;

                case 'client_email':
                    $query->where('client_email', 'LIKE', "%{$search}%");
                    break;

                case 'client_number':
                    $query->where('client_number', 'LIKE', "%{$search}%");
                    break;
            }
        }
        $clients = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $clients,
        ], 200);
    }

    public function show($id)
    {
        $clients = ClientMaster::select('id', 'client_name', 'client_email', 'client_number', 'created_at', 'updated_at')->orderBy('id', 'DESC')->where('id', $id)->get();
        return ApiResponse::success('Clients fetched successfully', ClientMasterResource::collection($clients));
    }
    public function store(Request $request)
    {
        try {
            $validated = $request->validate(
                [
                    'client_name' => 'required|string|max:191|unique:clients_master,client_name',
                    'client_email' => 'nullable|email|max:191|unique:clients_master,client_email',
                    'client_number' => [
                        'nullable',
                        'regex:/^\+?[0-9\s\-]{5,20}$/',
                        'unique:clients_master,client_number'
                    ],

                ],
                [
                    'client_name.required' => 'Client name is required.',
                    'client_name.unique' => 'Client name already exists.',
                    'client_email.email' => 'Please provide a valid email address.',
                    'client_email.unique' => 'Client email already exists.',
                    'client_number.regex' => 'Client number must be between 5 and 15 digits and may include +, spaces, or hyphens.',
                    'client_number.unique' => 'Client number already exists.',
                ]
            );

            $client = ClientMaster::create($validated);

            ActivityService::log([
                'client_id' => $client->id,
                'user_id' => auth()->user()->id,
                'type' => 'activity',
                'description' => $client->client_name . ' client added by ' . auth()->user()->name,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Client created successfully',
                'data' => new ClientMasterResource($client)
            ], 200);


        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while creating client.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function update(Request $request, $id)
    {
        try {
            $client = ClientMaster::find($id);

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client not found'
                ], 404);
            }

            $validated = $request->validate(
                [
                    'client_name' => [
                        'sometimes',
                        'required',
                        'string',
                        'max:191',
                        Rule::unique('clients_master', 'client_name')->ignore($id, 'id'),
                    ],

                    'client_email' => [
                        'sometimes',
                        'nullable',
                        'email',
                        'max:191',
                        Rule::unique('clients_master', 'client_email')->ignore($id, 'id'),
                    ],
                    'client_number' => [
                        'sometimes',
                        'nullable',
                        'regex:/^\+?[0-9][0-9\s\-]{9,14}$/',
                        Rule::unique('clients_master', 'client_number')->ignore($id, 'id'),
                    ],

                ],
                [
                    'client_name.required' => 'Client name is required.',
                    'client_name.unique' => 'Client name already exists.',
                    'client_email.email' => 'Please provide a valid email address.',
                    'client_email.unique' => 'Client email already exists.',
                    'client_number.regex' => 'Client number must be between 5 and 15 digits and may include +, spaces, or hyphens.',
                    'client_number.unique' => 'Client number already exists.',
                ]
            );

            // Update ONLY fields sent in request
            $client->update($validated);

            ActivityService::log([
                'client_id' => $client->id,
                'user_id' => auth()->user()->id,
                'type' => 'activity',
                'description' => $client->client_name . ' client updated by ' . auth()->user()->name,
            ]);

            return new ClientMasterResource($client);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while updating client.',
                'error' => $e->getMessage()
            ], 500);
        }
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
        ActivityService::log([
            'client_id' => $client->id,
            'user_id' => auth()->user()->id,
            'type' => 'activity',
            'description' => $client->client_name . ' Client deleted by ' . auth()->user()->name,
        ]);
        $client->delete();

        return response()->json([
            'success' => true,
            'message' => 'Client deleted successfully'
        ], 200);
    }
}
